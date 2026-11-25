# Pinned to PHP 8.4 — the platform target (composer.json >=8.4, CI runs 8.4).
# :latest drifted to PHP 8.5, whose new deprecations (e.g.
# ReflectionProperty::setAccessible()) pollute every HTTP response in dev.
#
# PINNED TO AN EXACT PATCH, not the floating `1-php8.4`, and that is the point
# rather than a detail. Dependabot has watched `package-ecosystem: docker` in
# this directory the whole time, but a floating tag gives it nothing to bump:
# the tag string never changes, so it opened no PR when PHP 8.4.27 shipped the
# fixes for CVE-2026-109618 and CVE-2026-109619 (both CVSS 9.2, unauthenticated
# RCE, exploited in the wild per watchTowr). A deployment running this image
# was still on PHP 8.4.26 three days later and nothing in the pipeline had
# noticed, because the only thing that rebuilds this image is a release, and a
# release is cut for application changes rather than for base-image ones.
#
# So the version is written out here where a bump is a diff somebody reviews.
# A stale exact pin is visible; a stale floating tag is not.
#
# Three stages (WC-172, WHIT-1221):
#   * base          — runtime only. Dev and staging composes build THIS stage
#                     and bind-mount the checkout over /app, so it ships no code.
#   * release-build — base + git/unzip/composer: installs vendor/ (no-dev) and
#                     bakes the build identity. Never published.
#   * release       — the publishable image: base without the compiler and
#                     headers, plus the finished /app from release-build. The
#                     release workflow builds this stage and pushes it to GHCR;
#                     container-based deployments run it as-is (see
#                     docs/wiki/Core-Update.md).
FROM dunglas/frankenphp:1-php8.4.28 AS base

# Install required packages for the PostgreSQL and zip extensions. libzip-dev
# backs ext-zip, which the staged plugin-upload installer (WC-220) needs to
# safely inspect/extract uploaded .zip packages via ZipArchive.
#
# `apt-get upgrade` takes Debian's security point releases at build time
# (WHIT-1336). The upstream image is rebuilt for PHP releases, not Debian ones:
# on 2026-11-22 the newest dunglas/frankenphp:1-php8.4 still shipped glibc
# 2.41-12+deb13u4 with deb13u5 already in trixie, and Trivy reported every
# glibc CVE fixed between them against this image. With the upgrade here, a
# rebuild clears them without waiting for upstream.
#
# APT_REFRESH is the build date (WHIT-1391). The registry layer cache
# (WHIT-1340) otherwise reuses this layer forever, so the upgrade above never
# runs again: on 2026-11-24 every develop image kept krb5 1.21.3-5+deb13u1 a
# day after deb13u2 was in trixie-security, and Trivy blocked them all. A new
# date re-runs it (and the layers after it) once a day; builds on the same day
# still share the cache.
ARG APT_REFRESH=""
RUN echo "apt refresh ${APT_REFRESH}" && apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y \
    libpq-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PostgreSQL + zip extensions
RUN docker-php-ext-install pgsql pdo_pgsql zip

# Upload limits, raised ABOVE the application's own ceilings on purpose.
#
# PHP ships upload_max_filesize=2M and post_max_size=8M. A form attachment is
# capped at 10 MiB by \Whity\Core\Form\FormUploadPolicy and a plugin package at
# 32 MiB by MultipartConfig — so on the stock ini, PHP refuses a 3 MB paper
# before a single line of that policy runs, with UPLOAD_ERR_INI_SIZE and no
# sentence anybody can act on. Worse, a body over post_max_size is DISCARDED
# entirely: $_POST and $_FILES arrive empty and the request looks like a client
# that sent nothing.
#
# The ini is therefore set ABOVE every application ceiling rather than equal to
# one, so the thing that refuses an oversized upload is always the application —
# which knows which endpoint it is, what that endpoint's limit is, and how to say
# so. The ini is the backstop for a request no endpoint would accept anyway.
#
# memory_limit is raised with them: a 32 MiB package arrives as a temp file but
# is read into a string to be inspected, and 128M leaves no room for the process
# doing that in a worker that is also holding a request.
RUN { \
      echo 'upload_max_filesize = 48M'; \
      echo 'post_max_size = 64M'; \
      echo 'memory_limit = 256M'; \
    } > "$PHP_INI_DIR/conf.d/zz-whity-uploads.ini"

# Settings that exist for what a response would otherwise give away, or for a
# request-parsing feature nothing here uses. Separate from the uploads ini on
# purpose: these are not tuning, and a later limits change should not have to
# reason about them.
#
# expose_php — PHP announces its exact patch version in X-Powered-By on every
# response, including ones the application never sees (a fatal during boot, a
# request refused before the kernel runs). That is a free answer to "is this
# host worth firing the current RCE at", and it dates the deployment besides.
# A sibling service in this estate turned it off for exactly that reason some
# time ago; core never did, and a live deployment was found announcing its
# patch level during the 2026-10 PHP advisories.
#
# session.upload_progress.enabled — the mitigation PHP published for
# CVE-2026-115560, which is unpatched as of today and affects even 8.5.12, and
# which also closes the known attack paths for CVE-2026-109619. It has to be an
# ini setting rather than anything in application code: PHP runs the upload
# progress handler from the SAPI while parsing a multipart body, and it starts
# a session by itself to do so, so an unauthenticated POST reaches it on a
# platform that — like this one — never calls session_start() anywhere.
# Nothing here reads upload progress, so turning it off costs us nothing.
#
# display_errors — the image never copies php.ini-production, so PHP's built-in
# default (display_errors=1) applied: a warning or deprecation inside a request
# was printed into the response body with file paths (READINESS CE-20). Errors
# go to the log instead, which is where the worker's error_log already writes.
RUN { \
      echo 'expose_php = Off'; \
      echo 'session.upload_progress.enabled = 0'; \
      echo 'display_errors = Off'; \
      echo 'display_startup_errors = Off'; \
      echo 'log_errors = On'; \
    } > "$PHP_INI_DIR/conf.d/zz-whity-security.ini"

# Set working directory
WORKDIR /app

# Default environment variables for FrankenPHP worker mode
ENV FRANKENPHP_WORKERS=8
ENV FRANKENPHP_TIMEOUT=60s
ENV MAX_REQUESTS=500

FROM base AS release-build

# Everything that only BUILDS the release lives in this stage: git, unzip and
# composer to install vendor/, and the build-identity step. The release stage
# below copies the finished /app out of it and ships none of these tools.

# Composer needs git + unzip to install dist packages (the frankenphp base
# image ships neither).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The application tree (.dockerignore keeps dev artifacts, tests, the web
# frontend and any locally deployed plugins out — only the reference plugins
# ship), then the autoloader for it, exactly as `composer install` writes it.
#
# Dependencies come first (WHIT-1340), so the install layer is keyed on the
# lock file and reused, from the registry layer cache images.yml configures, by
# every commit that does not change a dependency. composer.json resolves
# whity/plugin-sdk from the in-repo sdk/ path repository, and resolving it needs
# only sdk/composer.json; the package is linked into vendor/ and its sources
# arrive with the tree. No autoloader until the classes it maps are there.
# composer.json defines no install scripts, so --no-scripts skips nothing.
COPY composer.json composer.lock ./
COPY sdk/composer.json sdk/composer.json
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction \
        --no-autoloader --no-scripts \
    && composer clear-cache

COPY . /app
RUN composer dump-autoload --no-dev

# The image's IDENTITY, baked in beside the code it describes (#1049).
#
# `/api/health` reports CoreVersion::VERSION — a CONSTANT IN THE SOURCE, which
# says what the code claims to be and not what is running. Between releases it
# is identical across every commit, so nothing could tell a backend running
# today's develop from one running a checkout three weeks old; this instance's
# backend was found four days behind with a green health probe. GET /api/build
# answers that instead, and this is where its answer comes from.
#
# It CANNOT be derived here, at build time or at run time: .dockerignore drops
# .git, so the tree COPYied above is a copy with no revision metadata — `git`
# is installed for composer, and has nothing to read. The commit therefore
# arrives as a build arg — the same WHITY_BUILD_COMMIT release.yml already
# passes to all three image legs (its comment there said the app image
# "ignores it"; it no longer does).
#
# Placed AFTER composer install so rebuilding the same context under a new
# commit reuses the dependency layer. A build with no arg does not fail: the
# script writes nothing, /api/build reports source=unknown, and the release
# smoke job — which asserts the SERVED identity names the release commit — is
# what stops that state from being published.
#
# No matching ENV, deliberately: a build arg is already an environment variable
# for the RUN below, and promoting it to a runtime ENV would leave a SECOND
# commit in the container that nothing reads and that an operator could set to
# anything. The baked file is the one source, and nothing the container does
# afterwards can move it.
ARG WHITY_BUILD_COMMIT=""
RUN php scripts/write-build-identity.php

FROM base AS release

# The runtime ships no compiler and no headers (WHIT-1221).
#
# The PHP base image keeps its PHPIZE_DEPS (gcc, g++, make, autoconf, libc6-dev)
# installed so docker-php-ext-install works, and the base stage above adds the
# libpq/libzip headers to build pgsql and zip. A running Whity needs none of it:
# the extensions are already compiled. Left in, they were most of the image's
# Trivy findings: libc6-dev pulls linux-libc-dev, the kernel headers, and every
# kernel CVE is reported against that package whether or not a header is ever
# read. The shared libraries the two extensions link (libpq5, libzip5) are
# marked manual first so the purge keeps them. libc6-dev is named on its own
# because the base image marks it manual, so auto-remove would leave it. The
# base stage is untouched: dev and staging composes build it and may still
# install extensions there.
RUN apt-mark manual libpq5 libzip5 \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libc6-dev libpq-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && php -m | grep -qx pgsql \
    && php -m | grep -qx pdo_pgsql \
    && php -m | grep -qx zip

COPY --from=release-build /app /app

WORKDIR /app

# The reference plugins (HelloWorld, ExamplePlugin) ship in the image as
# examples but do not run in it (#1831): no routes, roles, jobs or migrations
# from them on a real deployment. Set WHITY_REFERENCE_PLUGINS=on to run them.
# Development, CI and e2e build the `base` stage, where it is unset (= on).
ENV WHITY_REFERENCE_PLUGINS=off

# READINESS CO-26: the app and every worker run as www-data. The entrypoint
# starts as root only to hand the writable directories to www-data (once per
# volume) and then drops to it before running anything; WHITY_RUN_AS_ROOT=1
# is the roll-back switch. See docker/production/whity-entrypoint.sh.
#
# The base image gives frankenphp a file capability (cap_net_bind_service). With
# `cap_drop: ALL` the kernel refuses to exec a file that asks for a capability
# outside the bounding set ("Operation not permitted"), so it is removed here:
# copying the binary drops the attribute. www-data binds :80 without it, since
# Docker sets net.ipv4.ip_unprivileged_port_start=0 in a container's namespace.
COPY docker/production/whity-entrypoint.sh /usr/local/bin/whity-entrypoint
RUN chmod 0755 /usr/local/bin/whity-entrypoint     && command -v setpriv >/dev/null     && cp /usr/local/bin/frankenphp /tmp/frankenphp     && mv /tmp/frankenphp /usr/local/bin/frankenphp     && chmod 0755 /usr/local/bin/frankenphp     && test -z "$(getcap /usr/local/bin/frankenphp)"
ENTRYPOINT ["whity-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
