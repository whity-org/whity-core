<?php

declare(strict_types=1);

/**
 * Run every migration into a fresh SQLite database in a process nothing else
 * has touched, and print the result as a serialize()d array:
 * ['objects' => sqlite_master rows, 'tables' => [name => rows with rowid]].
 *
 * SchemaFromMigrationsSqliteTemplateTest compares this with a template copy
 * built in a process whose state a test has changed (WHIT-949).
 */

require dirname(__DIR__) . '/bootstrap.php';

$_ENV['PHPUNIT_SQLITE_NO_TEMPLATE'] = '1';
foreach (array_slice($argv, 1) as $assignment) {
    [$name, $value] = explode('=', $assignment, 2) + [1 => ''];
    $_ENV[$name] = $value;
    putenv($name . '=' . $value);
}

$pdo = Tests\Support\SchemaFromMigrations::make();

$fetch = static function (string $sql) use ($pdo): array {
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Query failed: ' . $sql);
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

$tables = [];
foreach ($fetch("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $row) {
    $name = (string) $row['name'];
    $tables[$name] = $name === 'sqlite_sequence'
        ? $fetch('SELECT name, seq FROM sqlite_sequence ORDER BY name')
        : $fetch('SELECT rowid AS whity_rowid, * FROM "' . $name . '" ORDER BY rowid');
}

echo serialize([
    'objects' => $fetch('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY name'),
    'tables'  => $tables,
]);
