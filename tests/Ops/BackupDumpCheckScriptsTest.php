<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * WHIT-1211 (#1908): backup-db.sh, restore-db.sh and the freshness exporter
 * must tell an empty or invalid dump from a real one.
 *
 * The case that started it: after a reboot the backup ran before PostgreSQL
 * accepted connections and wrote a 20-byte archive, a valid gzip of nothing,
 * and marked it a success. The newest file was then the worst one; "restore
 * the newest" restored an empty database and the freshness alert stayed green.
 *
 * Each script runs as a separate process, the way cron and an operator run
 * it, and the exit code and output are the contract. pg_dump and pg_isready
 * are stubs on PATH, so no database is needed; the drill workflow
 * (.github/workflows/backup-restore-drill.yml) does the same against a real
 * PostgreSQL.
 */
final class BackupDumpCheckScriptsTest extends TestCase
{
    private const KEY = 'test-encryption-key-min-32-chars-aaaaaaaa';

    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The backup scripts are bash for Linux hosts.');
        }
        foreach (['bash', 'gzip', 'openssl'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool . ' 2>/dev/null')) === '') {
                self::markTestSkipped($tool . ' is not installed.');
            }
        }

        $dir = sys_get_temp_dir() . '/whity-backup-check-' . bin2hex(random_bytes(6));
        mkdir($dir . '/backups', 0o777, true);
        mkdir($dir . '/bin');
        mkdir($dir . '/textfile');
        $this->dir = $dir;

        // pg_isready: the database is up. pg_dump: prints FAKE_DUMP_FILE (if
        // set) and exits with FAKE_DUMP_EXIT, so a test picks what it "dumps".
        $this->writeExecutable('bin/pg_isready', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable(
            'bin/pg_dump',
            "#!/usr/bin/env bash\n"
            . "[ -n \"\${FAKE_DUMP_FILE:-}\" ] && cat \"\$FAKE_DUMP_FILE\"\n"
            . "exit \"\${FAKE_DUMP_EXIT:-0}\"\n"
        );
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            shell_exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    // ── restore-db.sh --check ────────────────────────────────────────────

    public function testAWholeDumpPasses(): void
    {
        $file = $this->gzipDump('good.sql.gz', $this->plainDump(8));

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '100']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('ok: plain dump', $out);
        self::assertStringContainsString('8 table-data entries', $out);
    }

    public function testTheTwentyByteEmptyGzipIsRefusedBySize(): void
    {
        $file = $this->gzipDump('empty.sql.gz', '');
        self::assertSame(20, filesize($file), 'gzip of nothing is the 20-byte archive from #1908');

        [$code, $out] = $this->restore(['--check', $file]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('REFUSED', $out);
        self::assertStringContainsString('below the 10240-byte floor', $out);
    }

    public function testAnEmptyGzipIsRefusedEvenWithNoSizeFloor(): void
    {
        $file = $this->gzipDump('empty.sql.gz', '');

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('decodes to nothing', $out);
    }

    public function testAZeroByteFileIsRefused(): void
    {
        $file = $this->dir . '/zero.sql.gz';
        touch($file);

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('the file is empty', $out);
    }

    public function testADamagedArchiveIsRefused(): void
    {
        $whole = (string) gzencode(str_repeat($this->plainDump(8), 20), 9);
        $file = $this->dir . '/cut.sql.gz';
        file_put_contents($file, substr($whole, 0, intdiv(strlen($whole), 2)));

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('gzip -t fails', $out);
    }

    public function testADumpCutShortIsRefused(): void
    {
        $file = $this->gzipDump('short.sql.gz', $this->plainDump(8, complete: false));

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('no "dump complete" trailer', $out);
    }

    public function testADumpWithTooLittleTableDataIsRefused(): void
    {
        $file = $this->gzipDump('few.sql.gz', $this->plainDump(2));

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('only 2 table-data entries, below the minimum of 5', $out);
    }

    public function testSomethingThatIsNotADumpIsRefused(): void
    {
        $file = $this->gzipDump('notes.sql.gz', str_repeat("just some text\n", 50));

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('REFUSED', $out);
    }

    public function testAnEncryptedDumpIsCheckedWithTheKeyAndRefusedWithTheWrongOne(): void
    {
        $file = $this->encrypt($this->gzipDump('enc.sql.gz', $this->plainDump(8)));

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0', 'BACKUP_ENCRYPTION_KEY' => self::KEY]);
        self::assertSame(0, $code, $out);

        [$code, $out] = $this->restore(['--check', $file], ['BACKUP_MIN_BYTES' => '0', 'BACKUP_ENCRYPTION_KEY' => 'not-the-key']);
        self::assertSame(1, $code, $out);
        self::assertStringContainsString('does not decrypt', $out);
    }

    public function testAMissingFileIsRefused(): void
    {
        [$code, $out] = $this->restore(['--check', $this->dir . '/nope.sql.gz']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('not found', $out);
    }

    // ── restore-db.sh with no file: newest VALID, not newest ─────────────

    public function testWithNoFileItPicksTheNewestValidDumpAndNamesTheSkippedOnes(): void
    {
        $good = $this->gzipDump('backups/whity_core_20261101T023000Z.sql.gz', $this->plainDump(8));
        $empty = $this->gzipDump('backups/whity_core_20261102T023000Z.sql.gz', '');
        $short = $this->gzipDump('backups/whity_core_20261103T023000Z.sql.gz', $this->plainDump(8, complete: false));
        touch($good, time() - 7200);
        touch($empty, time() - 3600);
        touch($short, time());

        [$code, $out] = $this->restore(['--check'], ['BACKUP_DIR' => $this->dir . '/backups', 'BACKUP_MIN_BYTES' => '0']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('skipping ' . $short, $out);
        self::assertStringContainsString('skipping ' . $empty, $out);
        self::assertStringContainsString('newest valid dump: ' . $good, $out);
    }

    public function testWithNoFileAndNoValidDumpItRefuses(): void
    {
        $this->gzipDump('backups/whity_core_20261102T023000Z.sql.gz', '');

        [$code, $out] = $this->restore(['--check'], ['BACKUP_DIR' => $this->dir . '/backups']);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('REFUSED: no valid dump', $out);
    }

    // ── backup-db.sh: a bad dump never becomes a backup ──────────────────

    public function testAFailingPgDumpLeavesNoDumpAndNoMarker(): void
    {
        [$code, $out] = $this->backup(['FAKE_DUMP_EXIT' => '1']);

        self::assertNotSame(0, $code, $out);
        self::assertFileDoesNotExist($this->dir . '/backups/.last-success');
        self::assertSame([], $this->backupFiles(), $out);
    }

    public function testAnEmptyDumpIsKeptAsInvalidWithoutAMarker(): void
    {
        [$code, $out] = $this->backup([]);

        self::assertNotSame(0, $code, $out);
        self::assertStringContainsString('not usable', $out);
        self::assertFileDoesNotExist($this->dir . '/backups/.last-success');
        $files = $this->backupFiles();
        self::assertCount(1, $files, $out);
        self::assertStringEndsWith('.sql.gz.invalid', $files[0]);
    }

    public function testAWholeDumpIsEncryptedAndMarked(): void
    {
        $source = $this->dir . '/source.sql';
        file_put_contents($source, $this->plainDump(8));

        [$code, $out] = $this->backup([
            'FAKE_DUMP_FILE' => $source,
            'BACKUP_MIN_BYTES' => '100',
            'BACKUP_ENCRYPTION_KEY' => self::KEY,
        ]);

        self::assertSame(0, $code, $out);
        self::assertFileExists($this->dir . '/backups/.last-success');
        $files = $this->backupFiles();
        self::assertCount(1, $files, $out);
        self::assertStringEndsWith('.sql.gz.enc', $files[0]);

        [$code, $out] = $this->restore(['--check'], [
            'BACKUP_DIR' => $this->dir . '/backups',
            'BACKUP_MIN_BYTES' => '100',
            'BACKUP_ENCRYPTION_KEY' => self::KEY,
        ]);
        self::assertSame(0, $code, $out);
    }

    // ── the freshness exporter ───────────────────────────────────────────

    public function testTheExporterReportsAnEmptyNewestDumpEvenWithAFreshMarker(): void
    {
        $good = $this->gzipDump('backups/whity_core_20261101T023000Z.sql.gz', $this->plainDump(8));
        touch($good, time() - 3600);
        $this->gzipDump('backups/whity_core_20261102T023000Z.sql.gz', '');
        file_put_contents($this->dir . '/backups/.last-success', gmdate('Ymd\THis\Z') . "\n");

        $metrics = $this->exporter(['BACKUP_MIN_BYTES' => '0']);

        self::assertMatchesRegularExpression('/^whity_backup_last_success_timestamp_seconds \d+$/m', $metrics);
        self::assertStringContainsString("\nwhity_backup_latest_valid 0\n", $metrics);
        self::assertStringContainsString("\nwhity_backup_latest_verified 1\n", $metrics);
        self::assertStringContainsString("\nwhity_backup_latest_size_bytes 20\n", $metrics);
    }

    public function testTheExporterReportsAWholeNewestDumpAsValid(): void
    {
        $this->gzipDump('backups/whity_core_20261101T023000Z.sql.gz', $this->plainDump(8));

        $metrics = $this->exporter(['BACKUP_MIN_BYTES' => '0']);

        self::assertStringContainsString("\nwhity_backup_latest_valid 1\n", $metrics);
        self::assertStringContainsString("\nwhity_backup_latest_table_data_entries 8\n", $metrics);
    }

    public function testWithoutTheKeyAnEncryptedDumpIsOnlySizeChecked(): void
    {
        $plain = $this->gzipDump('whity_core_20261101T023000Z.sql.gz', $this->plainDump(8));
        rename($this->encrypt($plain), $this->dir . '/backups/whity_core_20261101T023000Z.sql.gz.enc');

        $metrics = $this->exporter(['BACKUP_MIN_BYTES' => '0']);

        self::assertStringContainsString("\nwhity_backup_latest_valid 1\n", $metrics);
        self::assertStringContainsString("\nwhity_backup_latest_verified 0\n", $metrics);
    }

    public function testTheExporterReportsNoDumpAsInvalid(): void
    {
        $metrics = $this->exporter([]);

        self::assertStringContainsString("\nwhity_backup_latest_valid 0\n", $metrics);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A plain pg_dump-shaped SQL dump with $tables COPY blocks. */
    private function plainDump(int $tables, bool $complete = true): string
    {
        $sql = "--\n-- PostgreSQL database dump\n--\n\nSET statement_timeout = 0;\n\n";
        for ($i = 1; $i <= $tables; $i++) {
            $sql .= "CREATE TABLE public.t{$i} (id integer);\n";
            $sql .= "COPY public.t{$i} (id) FROM stdin;\n1\n2\n\\.\n\n";
        }
        if ($complete) {
            $sql .= "--\n-- PostgreSQL database dump complete\n--\n\n";
        }

        return $sql;
    }

    private function gzipDump(string $relative, string $sql): string
    {
        $path = $this->dir . '/' . $relative;
        $plain = $path . '.src';
        file_put_contents($plain, $sql);
        // Real gzip, so the empty case is the exact 20-byte archive.
        shell_exec('gzip -9 -n -c < ' . escapeshellarg($plain) . ' > ' . escapeshellarg($path));
        unlink($plain);

        return $path;
    }

    private function encrypt(string $path): string
    {
        $enc = $path . '.enc';
        shell_exec(sprintf(
            'openssl enc -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass %s',
            escapeshellarg($path),
            escapeshellarg($enc),
            escapeshellarg('pass:' . self::KEY)
        ));
        unlink($path);

        return $enc;
    }

    private function writeExecutable(string $relative, string $body): void
    {
        file_put_contents($this->dir . '/' . $relative, $body);
        chmod($this->dir . '/' . $relative, 0o755);
    }

    /** @return list<string> Base names in backups/, dot-files excluded. */
    private function backupFiles(): array
    {
        $names = array_values(array_filter(
            (array) scandir($this->dir . '/backups'),
            static fn ($n): bool => is_string($n) && $n !== '' && $n[0] !== '.'
        ));

        return array_map('strval', $names);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string} Exit code and combined output.
     */
    private function restore(array $args, array $env = []): array
    {
        return $this->runScript('scripts/restore-db.sh', $args, $env);
    }

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string}
     */
    private function backup(array $env): array
    {
        return $this->runScript('scripts/backup-db.sh', [], $env + [
            'DB_USER' => 'whity',
            'DB_PASSWORD' => 'unused',
            'DB_NAME' => 'core',
            'BACKUP_DIR' => $this->dir . '/backups',
        ]);
    }

    /** @param array<string, string> $env */
    private function exporter(array $env): string
    {
        [$code, $out] = $this->runScript('ops/alerting/backup-freshness-textfile.sh', [], $env + [
            'BACKUP_DIR' => $this->dir . '/backups',
            'TEXTFILE_DIR' => $this->dir . '/textfile',
        ]);
        self::assertSame(0, $code, $out);

        return "\n" . (string) file_get_contents($this->dir . '/textfile/whity_backup.prom');
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string}
     */
    private function runScript(string $script, array $args, array $env): array
    {
        $root = dirname(__DIR__, 2);
        $base = getenv();
        foreach (['BACKUP_DIR', 'BACKUP_ENCRYPTION_KEY', 'BACKUP_MIN_BYTES', 'BACKUP_MIN_TABLE_DATA', 'BACKUP_S3_BUCKET', 'BACKUP_CHECK_CACHE', 'BACKUP_CHECK_LIB', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'FAKE_DUMP_FILE', 'FAKE_DUMP_EXIT'] as $name) {
            unset($base[$name]);
        }
        $base['PATH'] = $this->dir . '/bin:' . ($base['PATH'] ?? '/usr/bin:/bin');

        $process = proc_open(
            // stderr joins stdout inside bash, so the output keeps its order.
            array_merge(['bash', '-c', 'exec bash "$0" "$@" 2>&1', $root . '/' . $script], $args),
            [1 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            $env + $base
        );
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), is_string($out) ? $out : ''];
    }
}
