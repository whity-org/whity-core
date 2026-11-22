<?php

declare(strict_types=1);

namespace Whity\Database\Migration;

/**
 * Static checks for migration patterns that are dangerous on a big PostgreSQL
 * database (WHIT-1240): what locks a busy table for minutes, rewrites it,
 * or can't be run twice. See docs/ops/migrations.md for each rule and the safe
 * way to write the same change.
 *
 * It reads the SQL in a migration's string literals (PHP comments are not
 * read, and neither is `down()`), so it is a lint, not a parser: it can be
 * wrong both ways. A migration that knows better says so in a comment, with a
 * reason, and the rule is off for that file:
 *
 *     // migration-lint: allow index-not-concurrent (table is new in 0.2.31, still empty)
 *
 * A table is LARGE when it has a tenant_id column (it grows with every
 * company) or is listed in database/migration-lint.json. A risky change to a
 * large table is an error; to any other existing table, a warning. Tables the
 * same migration creates are empty, so nothing is risky on them.
 */
final class MigrationLint
{
    public const RULES = [
        'index-not-concurrent' => 'CREATE INDEX on an existing table without CONCURRENTLY blocks every write to it until the index is built.',
        'add-column-rewrite' => 'Adding a SERIAL, GENERATED … STORED or volatile-default column rewrites the whole table under an exclusive lock.',
        'add-column-not-null' => 'Adding a NOT NULL column without a DEFAULT fails on any table that already has rows.',
        'alter-column-type' => 'Changing a column type rewrites the table (and its indexes) under an exclusive lock.',
        'set-not-null' => 'SET NOT NULL scans the whole table under an exclusive lock.',
        'constraint-not-valid' => 'Adding a foreign key, CHECK, UNIQUE or PRIMARY KEY constraint checks every row under a lock; add it NOT VALID (or USING INDEX) and validate separately.',
        'unbatched-backfill' => 'An UPDATE or DELETE over a whole existing table holds row locks and bloats it in one transaction; use Backfill::inBatches().',
        'long-lock' => 'LOCK TABLE, VACUUM FULL, CLUSTER, REINDEX and non-concurrent REFRESH hold an exclusive lock for as long as they run.',
        'not-idempotent' => 'Migrations must be safe to run twice: CREATE … IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, DROP … IF EXISTS.',
    ];

    /** @var array<string, true> */
    private array $largeTables = [];

    /**
     * @param list<string> $largeTables Tables to treat as large.
     */
    public function __construct(array $largeTables)
    {
        foreach ($largeTables as $table) {
            $this->largeTables[strtolower($table)] = true;
        }
    }

    /**
     * Built from the repository's own sources: tables with a tenant_id column
     * in the baseline or in any migration, plus the configured extras.
     */
    public static function forRepository(string $root): self
    {
        $config = [];
        $configFile = $root . '/database/migration-lint.json';
        if (is_file($configFile)) {
            $decoded = json_decode((string) file_get_contents($configFile), true);
            $config = is_array($decoded) ? $decoded : [];
        }

        $sources = [];
        $baseline = $root . '/database/baseline/baseline.pgsql.sql';
        if (is_file($baseline)) {
            $sources[] = (string) file_get_contents($baseline);
        }
        foreach (glob($root . '/database/migrations/*.php') ?: [] as $file) {
            $sources[] = (string) file_get_contents($file);
        }

        $large = [];
        foreach ($sources as $source) {
            foreach (self::tablesWithTenantId($source) as $table) {
                $large[$table] = true;
            }
        }
        foreach ((array) ($config['large_tables'] ?? []) as $table) {
            $large[strtolower((string) $table)] = true;
        }
        foreach ((array) ($config['small_tables'] ?? []) as $table) {
            unset($large[strtolower((string) $table)]);
        }

        return new self(array_keys($large));
    }

    /** @return list<string> */
    private static function tablesWithTenantId(string $source): array
    {
        $tables = [];
        // CREATE TABLE [IF NOT EXISTS] [public.]name ( ... ) — the body up to
        // the closing paren at the start of a line or the statement end.
        if (preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:public\.)?"?(\w+)"?\s*\((.*?)\n\s*\)\s*;?/is',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                if (preg_match('/\btenant_id\b/i', $m[2]) === 1) {
                    $tables[] = strtolower($m[1]);
                }
            }
        }
        if (preg_match_all('/ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?(?:public\.)?"?(\w+)"?\s+ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?tenant_id\b/i', $source, $m2)) {
            foreach ($m2[1] as $table) {
                $tables[] = strtolower($table);
            }
        }

        return $tables;
    }

    public function isLarge(string $table): bool
    {
        return isset($this->largeTables[strtolower($table)]);
    }

    /**
     * @return list<array{migration: string, rule: string, severity: string, line: int, message: string, sql: string}>
     */
    public function lintFile(string $file): array
    {
        return $this->lintSource(pathinfo($file, PATHINFO_FILENAME), (string) file_get_contents($file));
    }

    /**
     * @return list<array{migration: string, rule: string, severity: string, line: int, message: string, sql: string}>
     */
    public function lintSource(string $migration, string $php): array
    {
        $allowed = self::allowedRules($php);
        $fragments = self::sqlFragments($php);

        $created = [];
        foreach ($fragments as [$sql]) {
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?(\w+)"?/i', $sql, $m)) {
                foreach ($m[1] as $table) {
                    $created[strtolower($table)] = true;
                }
            }
        }

        // Guards written in PHP (a pg_constraint lookup, a try/catch around the
        // statement) make a constraint add idempotent without SQL saying so.
        $constraintGuarded = preg_match('/pg_constraint|table_constraints|constraintExists|catch\s*\(/i', $php) === 1;

        $findings = [];
        $add = function (string $rule, bool $large, int $line, string $sql, ?string $severity = null) use (&$findings, $migration, $allowed): void {
            if (isset($allowed[$rule])) {
                return;
            }
            $findings[] = [
                'migration' => $migration,
                'rule' => $rule,
                'severity' => $severity ?? ($large ? 'error' : 'warning'),
                'line' => $line,
                'message' => self::RULES[$rule],
                'sql' => self::shorten($sql),
            ];
        };

        foreach ($fragments as [$fragment, $line]) {
            foreach (self::statements($fragment) as $sql) {
                $this->lintStatement($sql, $line, $created, $constraintGuarded, $add);
            }
        }

        return $findings;
    }

    /**
     * @param array<string, true> $created
     * @param callable(string, bool, int, string, ?string=): void $add
     */
    private function lintStatement(string $sql, int $line, array $created, bool $constraintGuarded, callable $add): void
    {
        $existing = fn(string $table): bool => !isset($created[strtolower($table)]);
        $large = fn(string $table): bool => $existing($table) && $this->isLarge($table);

        // ── CREATE INDEX ───────────────────────────────────────────────────
        if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+(CONCURRENTLY\s+)?(IF\s+NOT\s+EXISTS\s+)?.*?\bON\s+(?:ONLY\s+)?"?(\w+)"?/is', $sql, $m)) {
            $table = $m[3];
            if ($m[1] === '' && $existing($table)) {
                $add('index-not-concurrent', $large($table), $line, $sql);
            }
            if ($m[2] === '') {
                $add('not-idempotent', true, $line, $sql);
            }

            return;
        }

        if (preg_match('/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $sql)) {
            $add('not-idempotent', true, $line, $sql);

            return;
        }

        if (preg_match('/^\s*CREATE\s+EXTENSION\s+(?!IF\s+NOT\s+EXISTS)/i', $sql)) {
            $add('not-idempotent', true, $line, $sql);

            return;
        }

        if (preg_match('/^\s*DROP\s+(TABLE|INDEX|VIEW|TRIGGER|FUNCTION|SEQUENCE|TYPE)\s+(?!IF\s+EXISTS)(?!CONCURRENTLY\s+IF\s+EXISTS)/i', $sql)) {
            $add('not-idempotent', true, $line, $sql);
        }

        // ── ALTER TABLE ────────────────────────────────────────────────────
        if (preg_match('/^\s*ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?"?(\w+)"?\s+(.*)$/is', $sql, $m)) {
            $table = $m[1];
            $body = $m[2];
            if (!$existing($table)) {
                return;
            }
            $isLarge = $large($table);

            foreach (self::alterActions($body) as $action) {
                if (preg_match('/^ADD\s+(?:COLUMN\s+)?(?!CONSTRAINT\b)(?!PRIMARY\b)(?!UNIQUE\b)(?!FOREIGN\b)(?!CHECK\b)(IF\s+NOT\s+EXISTS\s+)?"?\w+"?\s+(.*)$/is', $action, $a)) {
                    if ($a[1] === '') {
                        $add('not-idempotent', true, $line, $sql);
                    }
                    $definition = $a[2];
                    if (preg_match('/^\s*(BIG|SMALL)?SERIAL\b/i', $definition)
                        || preg_match('/GENERATED\s+ALWAYS\s+AS\s*\(.*\)\s*STORED/is', $definition)
                        || preg_match('/DEFAULT\s+[^,]*\b(random|gen_random_uuid|uuid_generate_v\d|clock_timestamp|timeofday|nextval)\s*\(/i', $definition)) {
                        $add('add-column-rewrite', $isLarge, $line, $sql);
                    }
                    if (preg_match('/\bNOT\s+NULL\b/i', $definition) && !preg_match('/\bDEFAULT\b/i', $definition)) {
                        $add('add-column-not-null', true, $line, $sql);
                    }
                    if (preg_match('/\bREFERENCES\b/i', $definition)) {
                        $add('constraint-not-valid', false, $line, $sql, 'warning');
                    }
                    continue;
                }

                if (preg_match('/^ALTER\s+(?:COLUMN\s+)?"?\w+"?\s+(?:SET\s+DATA\s+)?TYPE\s+(\w+)/i', $action, $ty)) {
                    // Widening a VARCHAR, or VARCHAR to TEXT, changes only the
                    // catalogue; anything else rewrites. The lint can't see the
                    // old type, so those two are warnings to check by hand.
                    $widening = preg_match('/^(VARCHAR|CHARACTER|TEXT)$/i', $ty[1]) === 1;
                    $add('alter-column-type', $isLarge && !$widening, $line, $sql);
                    continue;
                }

                if (preg_match('/^ALTER\s+(?:COLUMN\s+)?"?\w+"?\s+SET\s+NOT\s+NULL\b/i', $action)) {
                    $add('set-not-null', $isLarge, $line, $sql);
                    continue;
                }

                if (preg_match('/^ADD\s+(CONSTRAINT\s+"?\w+"?\s+)?(FOREIGN\s+KEY|CHECK|UNIQUE|PRIMARY\s+KEY|EXCLUDE)\b/i', $action, $c)) {
                    $kind = strtoupper((string) preg_replace('/\s+/', ' ', $c[2]));
                    $safe = preg_match('/\bNOT\s+VALID\b/i', $action) === 1 || preg_match('/\bUSING\s+INDEX\b/i', $action) === 1;
                    if (!$safe) {
                        $add('constraint-not-valid', $isLarge, $line, $sql);
                    }
                    if (!$constraintGuarded && $kind !== 'CHECK') {
                        $add('not-idempotent', false, $line, $sql, 'warning');
                    }
                    continue;
                }

                if (preg_match('/^DROP\s+(COLUMN\s+)?(?!CONSTRAINT\b)(?!IF\b)"?\w+"?/i', $action) && !preg_match('/^DROP\s+(COLUMN\s+)?IF\s+EXISTS/i', $action)) {
                    $add('not-idempotent', true, $line, $sql);
                    continue;
                }

                if (preg_match('/^DROP\s+CONSTRAINT\s+(?!IF\s+EXISTS)/i', $action)) {
                    $add('not-idempotent', false, $line, $sql, 'warning');
                }
            }

            return;
        }

        // ── Data changes ───────────────────────────────────────────────────
        if (preg_match('/^\s*UPDATE\s+(?:ONLY\s+)?"?(\w+)"?\s+(?:AS\s+\w+\s+)?SET\b(.*)$/is', $sql, $m)) {
            $table = $m[1];
            if ($existing($table) && !self::pinsOneRow($m[2]) && ($large($table) || !preg_match('/\bWHERE\b/i', $m[2]))) {
                $add('unbatched-backfill', $large($table), $line, $sql);
            }

            return;
        }

        if (preg_match('/^\s*DELETE\s+FROM\s+(?:ONLY\s+)?"?(\w+)"?(.*)$/is', $sql, $m)) {
            $table = $m[1];
            if ($existing($table) && $large($table) && !self::pinsOneRow($m[2])) {
                $add('unbatched-backfill', true, $line, $sql);
            }

            return;
        }

        // ── Long exclusive locks ───────────────────────────────────────────
        if (preg_match('/^\s*(LOCK\s+TABLE|VACUUM\s+FULL|CLUSTER\b|REINDEX\s+(?!.*CONCURRENTLY)|REFRESH\s+MATERIALIZED\s+VIEW\s+(?!CONCURRENTLY))/is', $sql)) {
            $add('long-lock', true, $line, $sql);
        }
    }

    /**
     * Whether a WHERE clause picks one row by its id (`id = :id`, `id = ?`):
     * a loop of those is already a batch of one, not a whole-table backfill.
     */
    private static function pinsOneRow(string $rest): bool
    {
        return preg_match('/\bWHERE\b.*?(?<![\w.])(?:\w+\.)?id\s*=\s*(?::\w+|\?|\d+)/is', $rest) === 1;
    }

    /**
     * Split an ALTER TABLE body into its comma-separated actions, ignoring
     * commas inside parentheses and quotes.
     *
     * @return list<string>
     */
    private static function alterActions(string $body): array
    {
        $actions = [];
        $depth = 0;
        $quote = false;
        $current = '';
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $ch = $body[$i];
            if ($ch === "'") {
                $quote = !$quote;
            } elseif (!$quote && $ch === '(') {
                $depth++;
            } elseif (!$quote && $ch === ')') {
                $depth--;
            } elseif (!$quote && $depth === 0 && $ch === ',') {
                $actions[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $actions[] = trim($current);

        return array_values(array_filter($actions, static fn(string $a): bool => $a !== ''));
    }

    /**
     * Split a fragment into statements on `;` outside quotes and dollar-quoted bodies.
     *
     * @return list<string>
     */
    private static function statements(string $fragment): array
    {
        // A DO $$ … $$ block is one statement whatever it holds.
        if (preg_match('/^\s*DO\s+\$/i', $fragment)) {
            return [];
        }
        $out = [];
        $quote = false;
        $current = '';
        $length = strlen($fragment);
        for ($i = 0; $i < $length; $i++) {
            $ch = $fragment[$i];
            if ($ch === "'") {
                $quote = !$quote;
            }
            if (!$quote && $ch === ';') {
                $out[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $out[] = trim($current);

        return array_values(array_filter(
            $out,
            static fn(string $s): bool => preg_match('/^(CREATE|ALTER|DROP|UPDATE|DELETE|LOCK|VACUUM|CLUSTER|REINDEX|REFRESH|INSERT)\b/i', $s) === 1
        ));
    }

    /**
     * The SQL-looking string literals of a migration's up() (and helpers), with
     * the line each starts on. Adjacent pieces joined by `.` are one fragment;
     * a PHP variable inside a string reads as the word `x`.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function sqlFragments(string $php): array
    {
        $tokens = token_get_all($php);
        $fragments = [];
        $buffer = '';
        $bufferLine = 0;
        $inString = false;

        // Which function body we are in, by brace depth.
        $depth = 0;
        $pendingFunction = null;
        $functionStack = [];

        $line = 1;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;
            } else {
                $id = null;
                $text = $token;
            }

            if ($id === T_FUNCTION) {
                $pendingFunction = '';
            } elseif ($pendingFunction === '' && $id === T_STRING) {
                $pendingFunction = strtolower($text);
            }

            if (!$inString) {
                if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                    if (is_string($pendingFunction) && $pendingFunction !== '') {
                        $functionStack[] = ['name' => $pendingFunction, 'depth' => $depth];
                        $pendingFunction = null;
                    }
                } elseif ($text === '}') {
                    $current = end($functionStack);
                    if (is_array($current) && $current['depth'] === $depth) {
                        array_pop($functionStack);
                    }
                    $depth--;
                } elseif ($text === ';' && $pendingFunction !== null) {
                    $pendingFunction = null; // abstract/interface method
                }
            }

            if ($text === '"' || $id === T_START_HEREDOC || $id === T_END_HEREDOC) {
                $inString = $text === '"' ? !$inString : $id === T_START_HEREDOC;
                if ($buffer === '') {
                    $bufferLine = $line;
                }
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                if ($buffer === '') {
                    $bufferLine = $line;
                }
                $quote = $text[0];
                $inner = substr($text, 1, -1);
                $buffer .= $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
                continue;
            }

            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                if ($buffer === '') {
                    $bufferLine = $line;
                }
                $buffer .= stripcslashes($text);
                continue;
            }

            if ($inString) {
                // A variable inside "…{$x}…".
                if ($id === T_VARIABLE || $id === T_STRING || $id === T_NUM_STRING) {
                    $buffer .= 'x';
                }
                continue;
            }

            // Between concatenated pieces: keep the fragment going.
            if ($text === '.' || $id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            if ($id === T_VARIABLE && $buffer !== '') {
                $buffer .= 'x';
                continue;
            }

            self::flush($buffer, $bufferLine, $fragments, $functionStack);
        }
        self::flush($buffer, $bufferLine, $fragments, $functionStack);

        return $fragments;
    }

    /**
     * End the current fragment: keep it when it holds SQL and is not in down().
     *
     * @param list<array{0: string, 1: int}>              $fragments
     * @param list<array{name: string, depth: int}>       $functionStack
     */
    private static function flush(string &$buffer, int $bufferLine, array &$fragments, array $functionStack): void
    {
        $current = $functionStack === [] ? null : $functionStack[count($functionStack) - 1];
        $inDown = $current !== null && $current['name'] === 'down';
        if ($buffer !== '' && !$inDown && preg_match('/\b(CREATE|ALTER|DROP|UPDATE|DELETE|INSERT|LOCK|VACUUM|CLUSTER|REINDEX|REFRESH)\b/i', $buffer) === 1) {
            $fragments[] = [trim($buffer), $bufferLine];
        }
        $buffer = '';
    }

    /**
     * Rules switched off for a file by `migration-lint: allow <rule>[, <rule>] <reason>`.
     *
     * @return array<string, true>
     */
    private static function allowedRules(string $php): array
    {
        $allowed = [];
        if (preg_match_all('/migration-lint:\s*allow\s+([a-z\-]+(?:\s*,\s*[a-z\-]+)*)/i', $php, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/\s*,\s*/', $list) ?: [] as $rule) {
                    $allowed[strtolower($rule)] = true;
                }
            }
        }

        return $allowed;
    }

    private static function shorten(string $sql): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($flat) > 140 ? substr($flat, 0, 137) . '...' : $flat;
    }
}
