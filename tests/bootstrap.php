<?php

declare(strict_types=1);

// One temp directory per ParaTest worker. ParaTest gives every worker process a
// distinct TEST_TOKEN; plain `vendor/bin/phpunit` sets none and is unaffected.
//
// Many tests build paths from sys_get_temp_dir() with FIXED names
// (`wc205_migrations_shared`, `whity-storage-test`, `whity_<slug>`, the
// heartbeat file) or scan it for leftovers (PluginInstallerTest globs
// `whity_plugin_upload_*` and asserts none remain). Serially that is fine; with
// workers running side by side, one worker's fixture is another's leftover, and
// one worker's cleanup deletes a directory another is still using. Pointing
// TMPDIR at a per-token subdirectory fixes every such path in one place instead
// of renaming each of them, and keeps fixing the ones written later.
//
// This must run before anything calls sys_get_temp_dir(): PHP resolves TMPDIR on
// the first call and caches the answer for the life of the process. Child
// processes the tests spawn (proc_open) inherit the same TMPDIR.
$paratestToken = getenv('TEST_TOKEN');
if (is_string($paratestToken) && $paratestToken !== '') {
    // TMPDIR is what PHP reads on Linux and macOS; Windows reads TMP / TEMP.
    $baseTmp = getenv('TMPDIR') ?: getenv('TMP') ?: getenv('TEMP') ?: '/tmp';
    $workerTmp = rtrim((string) $baseTmp, '/\\')
        . DIRECTORY_SEPARATOR . 'whity-paratest-' . getmypid() . '-' . $paratestToken;
    if (!is_dir($workerTmp) && !mkdir($workerTmp, 0o700, true) && !is_dir($workerTmp)) {
        throw new RuntimeException("Cannot create per-worker temp directory {$workerTmp}");
    }
    foreach (['TMPDIR', 'TMP', 'TEMP'] as $tmpVar) {
        putenv($tmpVar . '=' . $workerTmp);
        $_ENV[$tmpVar] = $workerTmp;
    }

    // One seeded PostgreSQL database per worker, opt-in. The Integration suite
    // has tests that boot the real host against the database named in
    // PHPUNIT_PG_DSN (the CI job's migrated and seeded `whity_core`) and write
    // to it, so workers sharing it would see each other's rows. CI makes
    // `<dbname>_w1` ... `<dbname>_wN` with `CREATE DATABASE ... TEMPLATE` after
    // seeding and sets PHPUNIT_PG_DB_PER_WORKER=1; each worker is pointed at its
    // own copy here, before any test reads the DSN. Child processes inherit it.
    // Tests that clone from SchemaFromMigrations' template are unaffected: they
    // work in a throwaway database of their own either way.
    $pgDsn = getenv('PHPUNIT_PG_DSN');
    $perWorker = getenv('PHPUNIT_PG_DB_PER_WORKER');
    if (is_string($pgDsn) && $pgDsn !== '' && is_string($perWorker) && $perWorker !== '' && $perWorker !== '0') {
        if (preg_match('/\bdbname=([^;]*)/i', $pgDsn, $m) !== 1 || $m[1] === '') {
            throw new RuntimeException('PHPUNIT_PG_DB_PER_WORKER needs a dbname= in PHPUNIT_PG_DSN');
        }
        $workerDb = $m[1] . '_w' . preg_replace('/\D/', '', $paratestToken);
        $workerDsn = (string) preg_replace('/\bdbname=[^;]*/i', 'dbname=' . $workerDb, $pgDsn);
        foreach (['PHPUNIT_PG_DSN' => $workerDsn, 'DB_NAME' => $workerDb] as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once __DIR__ . '/Support/CheapPasswordHashing.php';
