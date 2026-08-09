<?php
/**
 * Protected CLI migration runner for NexA AI.
 *
 * This file intentionally lives outside backend/api and refuses web execution.
 * Run it from the repository/deployment root before serving a release:
 *   php scripts/migrate.php
 *   php scripts/migrate.php --database=main
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once __DIR__ . '/../backend/api/config.php';

$options = getopt('', ['database::', 'dry-run']);
$databaseOption = strtolower((string)($options['database'] ?? 'all'));
if (!in_array($databaseOption, ['all', 'main', 'cache'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [--database=main|cache|all] [--dry-run]\n");
    exit(2);
}

$targets = [
    'main' => [
        'file' => DATA_DIR . 'nexa.sqlite',
        'directory' => __DIR__ . '/../backend/migrations/main',
        'legacy_table' => 'users',
        'expected' => defined('NEXA_MAIN_SCHEMA_VERSION') ? NEXA_MAIN_SCHEMA_VERSION : 3,
    ],
    'cache' => [
        'file' => DATA_DIR . 'nexa-cache.sqlite',
        'directory' => __DIR__ . '/../backend/migrations/cache',
        'legacy_table' => 'quizzes',
        'expected' => defined('NEXA_CACHE_SCHEMA_VERSION') ? NEXA_CACHE_SCHEMA_VERSION : 3,
    ],
];

function nexa_migration_table_exists(PDO $db): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute(['nexa_schema_migrations']);
    return $stmt->fetchColumn() !== false;
}

function nexa_migration_legacy_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function nexa_load_migration_files(string $directory): array
{
    $paths = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
    $files = [];
    foreach ($paths as $path) {
        $name = basename($path);
        if (!preg_match('/^(\d{3})_([a-z0-9_]+)\.php$/i', $name, $matches)) {
            throw new RuntimeException("Invalid migration filename: {$name}");
        }
        $files[] = [
            'version' => (int)$matches[1],
            'name' => substr($name, 0, -4),
            'path' => $path,
        ];
    }
    usort($files, static fn(array $left, array $right): int => $left['version'] <=> $right['version']);
    $expectedVersion = 1;
    foreach ($files as $file) {
        if ($file['version'] !== $expectedVersion) {
            throw new RuntimeException('Migration versions must be consecutive starting at 001.');
        }
        $expectedVersion++;
    }
    if ($files === []) {
        throw new RuntimeException("No migrations found in {$directory}");
    }
    return $files;
}

function nexa_open_migration_db(string $file): PDO
{
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create database directory: {$directory}");
    }
    $db = new PDO('sqlite:' . $file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 10000');
    return $db;
}

function nexa_read_applied_versions(PDO $db): array
{
    if (!nexa_migration_table_exists($db)) {
        return [];
    }
    $rows = $db->query('SELECT version, name FROM nexa_schema_migrations ORDER BY version')->fetchAll();
    $applied = [];
    foreach ($rows as $row) {
        $applied[(int)$row['version']] = (string)$row['name'];
    }
    return $applied;
}

function nexa_backup_database(string $file, string $label): string
{
    if (!is_file($file) || filesize($file) === 0) {
        return '';
    }
    $backupDirectory = DATA_DIR . 'backups';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0750, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException("Cannot create backup directory: {$backupDirectory}");
    }
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $label . '-' . gmdate('Ymd\THis\Z') . '.sqlite';
    if (!copy($file, $backupPath)) {
        throw new RuntimeException("Database backup failed for {$label}");
    }
    $walPath = $file . '-wal';
    if (is_file($walPath) && !copy($walPath, $backupPath . '-wal')) {
        throw new RuntimeException("Database WAL backup failed for {$label}");
    }
    return $backupPath;
}

function nexa_apply_one_migration(PDO $db, array $migration): void
{
    $migrationCallback = require $migration['path'];
    if (!is_callable($migrationCallback)) {
        throw new RuntimeException("Migration {$migration['name']} must return a callable.");
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $migrationCallback($db);
        $stmt = $db->prepare(
            'INSERT INTO nexa_schema_migrations (version, name, applied_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$migration['version'], $migration['name']]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        throw $error;
    }
}

function nexa_migrate_target(string $label, array $target, bool $dryRun): int
{
    $files = nexa_load_migration_files($target['directory']);
    $db = nexa_open_migration_db($target['file']);
    $metadataExists = nexa_migration_table_exists($db);
    $legacyExists = nexa_migration_legacy_table_exists($db, $target['legacy_table']);
    $applied = nexa_read_applied_versions($db);
    $pending = array_values(array_filter($files, static function (array $migration) use ($applied): bool {
        return !array_key_exists($migration['version'], $applied);
    }));

    if ($pending === []) {
        $current = $applied === [] ? 0 : max(array_keys($applied));
        if ($current !== (int)$target['expected']) {
            throw new RuntimeException("{$label} schema metadata is incomplete; expected version {$target['expected']}.");
        }
        echo "{$label}: already at schema version {$current}\n";
        return $current;
    }

    if ($dryRun) {
        if (!$metadataExists && $legacyExists) {
            echo "{$label}: legacy database will be adopted by applying migration 001\n";
        }
        foreach ($pending as $migration) {
            echo "{$label}: would apply {$migration['name']}\n";
        }
        return $pending[count($pending) - 1]['version'];
    }

    $backupPath = nexa_backup_database($target['file'], 'nexa-' . $label);
    if ($backupPath !== '') {
        echo "{$label}: backup created at {$backupPath}\n";
    }

    if (!$metadataExists) {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS nexa_schema_migrations (
    version    INTEGER PRIMARY KEY,
    name       TEXT NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $applied = [];
    }

    foreach ($files as $migration) {
        if (array_key_exists($migration['version'], $applied)) {
            continue;
        }
        echo "{$label}: applying {$migration['name']}\n";
        nexa_apply_one_migration($db, $migration);
        $applied[$migration['version']] = $migration['name'];
    }

    $current = $applied === [] ? 0 : max(array_keys($applied));
    if ($current !== (int)$target['expected']) {
        throw new RuntimeException("{$label} migration finished at version {$current}; expected {$target['expected']}.");
    }
    echo "{$label}: schema version {$current}\n";
    return $current;
}

try {
    $selected = $databaseOption === 'all' ? ['main', 'cache'] : [$databaseOption];
    foreach ($selected as $label) {
        nexa_migrate_target($label, $targets[$label], isset($options['dry-run']));
    }
    echo isset($options['dry-run']) ? "Migration dry run complete.\n" : "Database migrations complete.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}