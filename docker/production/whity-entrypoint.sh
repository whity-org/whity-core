#!/bin/sh
# Entrypoint of the release image (READINESS CO-26): the app and every worker
# run as www-data, not root.
#
# The container starts as root only to do two things, then drops to www-data
# for good before anything else runs:
#
#  1. Make the writable directories www-data's. An install that ran as root
#     has a storage volume full of root-owned files; this hands them over the
#     first time this image starts on it. A marker file records that it was
#     done, so later starts cost one `stat` per directory, not a walk of the
#     whole volume. The directories: /app/storage (uploads, documents,
#     backups), /app/plugins (installs and the manifest cache), and /data and
#     /config (Caddy's state). A directory that can't be changed (a host bind
#     mount on some platforms) is reported and left as it is.
#
#  2. Start the given command as www-data. Every container that runs this
#     image goes through here: the web server and the queue, scheduler, events
#     relay and health-watch workers, whatever command a compose file gives.
#
# Binding :80 needs no capability: Docker (20.10 and later) lets an
# unprivileged process bind low ports inside a container's own network
# namespace (net.ipv4.ip_unprivileged_port_start=0).
#
# Roll-back switch: WHITY_RUN_AS_ROOT=1 skips both steps and runs the command
# as root, exactly as before this change. Files www-data created stay readable
# by root, so nothing else needs undoing; and the next start without the switch
# hands over whatever root created meanwhile.
set -eu

if [ "$(id -u)" = "0" ] && [ "${WHITY_RUN_AS_ROOT:-}" != "1" ]; then
    owner_uid="$(id -u www-data)"
    for dir in /app/storage /app/plugins /data /config; do
        [ -d "$dir" ] || mkdir -p "$dir"
        marker="$dir/.whity-owner-www-data"
        if [ ! -f "$marker" ] || [ "$(stat -c %u "$dir")" != "$owner_uid" ]; then
            started="$(date +%s)"
            if chown -R www-data:www-data "$dir" 2>/dev/null; then
                touch "$marker" && chown www-data:www-data "$marker"
                echo "[entrypoint] $dir now belongs to www-data ($(( $(date +%s) - started ))s)" >&2
            else
                echo "[entrypoint] WARNING: could not give $dir to www-data; writes there may fail. Set WHITY_RUN_AS_ROOT=1 to run as root." >&2
            fi
        fi
    done

    exec setpriv --reuid=www-data --regid=www-data --init-groups -- docker-php-entrypoint "$@"
fi

# Running as root on purpose (the roll-back switch): anything created now is
# root's, so forget that the directories were handed over. The next start
# without the switch walks them again.
if [ "$(id -u)" = "0" ]; then
    for dir in /app/storage /app/plugins /data /config; do
        rm -f "$dir/.whity-owner-www-data" 2>/dev/null || true
    done
fi

exec docker-php-entrypoint "$@"
