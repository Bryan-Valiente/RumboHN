<?php

declare(strict_types=1);

use Rumbo\Backend\Config;
use Rumbo\Backend\Database;
use Rumbo\Backend\SqlMigrationRunner;

$backendRoot = require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$files = glob($backendRoot . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No se encontraron migraciones.\n");
    exit(1);
}

if ($dryRun) {
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('No se pudo leer la migración.');
        }
        printf("%s: %d sentencias\n", basename($file), count(SqlMigrationRunner::statements($sql)));
    }
    exit(0);
}

$pdo = (new Database(new Config()))->connection();
$lock = (int) $pdo->query("SELECT GET_LOCK('rumbo_hn_schema_migration', 15)")->fetchColumn();
if ($lock !== 1) {
    fwrite(STDERR, "No se pudo obtener el lock de migración.\n");
    exit(1);
}

try {
    $applied = [];
    try {
        $records = $pdo->query('SELECT version FROM schema_migrations')->fetchAll();
        foreach ($records as $record) {
            $applied[(string) $record['version']] = true;
        }
    } catch (Throwable) {
        // Migration 001 is responsible for creating the tracking table.
    }

    foreach ($files as $file) {
        if (!preg_match('/^([0-9]+)_/', basename($file), $matches)) {
            throw new RuntimeException('El archivo de migración no contiene una versión numérica.');
        }
        $version = $matches[1];
        if (isset($applied[$version])) {
            printf("%s: omitida (ya aplicada)\n", basename($file));
            continue;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('No se pudo leer la migración.');
        }
        $count = SqlMigrationRunner::apply($pdo, $sql);
        printf("%s: %d sentencias aplicadas\n", basename($file), $count);
        $applied[$version] = true;
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('rumbo_hn_schema_migration')");
}
