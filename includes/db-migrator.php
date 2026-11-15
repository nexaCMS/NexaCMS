<?php
/**
 * DiCimes Database Migrator (includes/db-migrator.php)
 */

require_once __DIR__ . '/csv-lib.php';

function di_resolve_csv_source_dir(?string $csvDir = null): string {
    global $config, $include_dir;
    if ($csvDir !== null) {
        return $csvDir;
    }
    $baseDir = $include_dir ?? ($config['abs'] ?? dirname(__DIR__));
    $loc = str_replace('\\', '/', trim((string) ($config['database']['location'] ?? 'admin/database'), '/\\'));
    if (preg_match('#^[A-Za-z]:/#', $loc) || str_starts_with($loc, '/')) {
        $parent = is_dir($loc) ? $loc : (rtrim($baseDir, '/\\') . '/admin/database');
    } else {
        $parent = rtrim($baseDir, '/\\') . '/' . $loc;
    }
    $name = trim((string) ($config['database']['csv_name'] ?? $config['database']['name'] ?? 'db'), '/\\');
    $candidate = rtrim($parent, '/\\') . '/' . $name;
    if (!is_dir($candidate)) {
        $candidate = rtrim($parent, '/\\') . '/db';
    }
    return $candidate;
}

function di_migrate_csv_to_active_db(?string $csvDir = null, $legacyFlag = null): array {
    $csvDir = di_resolve_csv_source_dir($csvDir);
    if (!is_dir($csvDir)) {
        return [];
    }
    $files = glob(rtrim($csvDir, '/\\') . '/*.csv') ?: [];
    $imported = [];
    foreach ($files as $csvFile) {
        $table = pathinfo($csvFile, PATHINFO_FILENAME);
        if ($table === '' || str_ends_with($table, '_') || !preg_match('/^[a-zA-Z0-9_\-]+$/', $table)) {
            continue;
        }
        $csv = new MyCSV($csvFile);
        $fields = $csv->fields();
        $rows = [];
        $csv->reset();
        while ($row = $csv->each()) {
            $rows[] = $row;
        }
        $csv->close();

        create_table($table, $fields);
        if (!empty($rows)) {
            multi_update_table($table, $rows, 'insert');
        }
        $imported[$table] = count($rows);
    }
    return $imported;
}

function di_export_active_db_to_csv(?array $tables = null, $targetCsvDir = null): array {
    // Tolerates the admin page's shorthand: no table list means "every
    // table", and a missing/boolean target means the default CSV dir.
    if (!is_string($targetCsvDir) || $targetCsvDir === '') {
        $targetCsvDir = di_resolve_csv_source_dir();
    }
    if ($tables === null) {
        $tables = list_tables();
    }
    if (!is_dir($targetCsvDir)) {
        @mkdir($targetCsvDir, 0755, true);
    }
    $exported = [];
    foreach ($tables as $table) {
        if (!table_exists($table)) {
            continue;
        }
        $tableData = get_table($table, 'id ASC');
        $csvPath = rtrim($targetCsvDir, '/\\') . '/' . $table;
        if (is_file($csvPath . '.csv')) {
            @unlink($csvPath . '.csv');
        }
        $csv = new MyCSV($csvPath);
        foreach ($tableData['fields'] as $f) {
            if (strcasecmp($f, 'id') !== 0) {
                $csv->add_field($f);
            }
        }
        foreach ($tableData['data'] as $row) {
            $csv->insert($row);
        }
        $csv->write();
        $csv->close();
        $exported[$table] = count($tableData['data']);
    }
    return $exported;
}
