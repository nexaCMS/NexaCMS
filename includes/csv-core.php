<?php
/**
 * DiCimes CSV Storage Core (includes/csv-core.php)
 */

$GLOBALS['di_db_driver'] = 'csv';

require_once __DIR__ . '/csv-lib.php';

function di_csv_storage_dir(): string {
    global $config, $include_dir;
    $baseDir = $include_dir ?? ($config['abs'] ?? dirname(__DIR__));
    $loc = str_replace('\\', '/', trim((string) ($config['database']['location'] ?? 'admin/database')));
    if (preg_match('#^[A-Za-z]:/#', $loc)) {
        $parent = is_dir($loc) ? $loc : (rtrim($baseDir, '/\\') . '/admin/database');
    } elseif (str_starts_with($loc, '/')) {
        $parent = is_dir($loc) ? $loc : (rtrim($baseDir, '/\\') . '/' . ltrim($loc, '/'));
    } else {
        $parent = rtrim($baseDir, '/\\') . '/' . trim($loc, '/');
    }
    $dbName = trim((string) ($config['database']['name'] ?? 'db'), '/\\');
    $dir = rtrim($parent, '/\\') . '/' . $dbName;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function di_csv_table_base(string $table): string {
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $table);
    $prefix = (string) ($GLOBALS['nexa_db_table_prefix'] ?? '');
    if ($prefix !== '' && !in_array($clean, ['network_sites', 'network_site_users', 'network_package_requests'], true)) {
        $clean = $prefix . $clean;
    }
    return di_csv_storage_dir() . '/' . $clean;
}

function table_exists(string $table): bool {
    return is_file(di_csv_table_base($table) . '.csv');
}

function list_tables(): array {
    $dir = di_csv_storage_dir();
    $tables = [];
    foreach (glob($dir . '/*.csv') ?: [] as $f) {
        $tables[] = basename($f, '.csv');
    }
    sort($tables);
    return $tables;
}

function drop_table(string $table): bool {
    $file = di_csv_table_base($table) . '.csv';
    if (is_file($file)) {
        return @unlink($file);
    }
    return true;
}

function create_table(string $table, array $fields): bool {
    $base = di_csv_table_base($table);
    if (!is_file($base . '.csv')) {
        $data = new MyCSV($base);
        foreach ($fields as $field) {
            if ($field !== '' && strcasecmp((string) $field, 'id') !== 0) {
                $data->add_field((string) $field);
            }
        }
        $data->write();
        $data->close();
        return true;
    }
    ensure_fields($table, $fields);
    return false;
}

function ensure_fields(string $table, array $fields): void {
    if (empty($fields)) return;
    $base = di_csv_table_base($table);
    if (!is_file($base . '.csv')) {
        create_table($table, $fields);
        return;
    }
    $data = new MyCSV($base);
    $existing = $data->fields();
    $added = false;
    foreach ($fields as $field) {
        $field = (string) $field;
        if ($field !== '' && $field !== '*' && !in_array($field, $existing, true)) {
            if ($data->add_field($field)) {
                $existing[] = $field;
                $added = true;
            }
        }
    }
    if ($added) {
        $data->write();
    }
    $data->close();
}

function get_table(string $table, ?string $sort = null): array {
    $base = di_csv_table_base($table);
    if (!is_file($base . '.csv')) {
        return ['fields' => ['id'], 'data' => []];
    }
    $csv = new MyCSV($base);
    if ($sort !== null && trim($sort) !== '') {
        $csv->sort($sort);
    }
    $fields = $csv->fields();
    $rows = [];
    $csv->reset();
    while ($row = $csv->each()) {
        $rows[] = $row;
    }
    $csv->close();
    return ['fields' => $fields, 'data' => $rows];
}

function has_match($needle, $stack): bool {
    if (is_array($needle)) {
        foreach ($needle as $n) {
            $n = (string) $n;
            if ($n === '') continue;
            $quoted = '/' . preg_quote($n, '/') . '/iu';
            if (is_array($stack)) {
                foreach ($stack as $s) {
                    if (preg_match($quoted, (string) $s)) return true;
                }
            } elseif (preg_match($quoted, (string) $stack)) {
                return true;
            }
        }
        return false;
    }
    $n = (string) $needle;
    if ($n === '') return true;
    return (bool) preg_match('/' . preg_quote($n, '/') . '/iu', (string) $stack);
}

function count_records(string $table, ?string $field = null, $value = null): int {
    $base = di_csv_table_base($table);
    if (!is_file($base . '.csv')) return 0;
    if ($field === null && $value === null) {
        $data = new MyCSV($base);
        $res = (int) $data->count();
        $data->close();
        return $res;
    }
    return count(get_result($table, 1, [$field], ['search' => [$field]], -1, [$value], true));
}

function get_result(
    string $table,
    int $page = 1,
    array $fields = [],
    array $sort = ['by' => 'id', 'order' => 'ASC'],
    int $limit = 25,
    $ids = null,
    bool $strict = true
): array {
    global $result_count, $allids;
    $base = di_csv_table_base($table);
    if (!is_file($base . '.csv')) {
        $result_count[$table] = 0;
        return [];
    }

    $data = new MyCSV($base);
    $tableFields = $data->fields();
    if ($table === 'posts') {
        $allids = $data->ids();
    }

    $selectFields = (empty($fields) || $fields === ['*']) ? $tableFields : $fields;

    if (!empty($sort['by'])) {
        $order = strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $byCol = (string) $sort['by'];
        if (in_array($byCol, $tableFields, true) || strcasecmp($byCol, 'id') === 0) {
            $data->sort($byCol . ' ' . $order);
        }
    }

    $searchCols = isset($sort['search'])
        ? (is_array($sort['search']) ? $sort['search'] : [$sort['search']])
        : ($ids !== null ? ['id'] : []);

    $idList = null;
    $needles = [];
    if ($ids !== null) {
        $idList = is_array($ids) ? array_map('strval', $ids) : [(string) $ids];
        if (!$strict) {
            $needles = array_values(array_filter(explode(' ', strtolower(implode(' ', $idList))), static fn($w) => $w !== ''));
        }
    }

    $matched = [];
    $data->reset();
    while ($row = $data->each()) {
        $keep = false;
        if ($idList === null) {
            $keep = true;
        } else {
            foreach ($searchCols as $col) {
                $cell = (string) ($row[$col] ?? '');
                if ($strict) {
                    if (in_array($cell, $idList, false)) {
                        $keep = true;
                        break;
                    }
                } else {
                    if (empty($needles) || has_match($needles, strtolower($cell))) {
                        $keep = true;
                        break;
                    }
                }
            }
        }
        if ($keep) {
            $projected = [];
            foreach ($selectFields as $f) {
                $projected[$f] = $row[$f] ?? '';
            }
            $matched[] = $projected;
        }
    }
    $data->close();

    $total = count($matched);
    $page = max(1, (int) $page);
    if ($limit === -1 || $limit <= 0) {
        $result_count[$table] = $total > 0 ? 1 : 0;
        return $matched;
    }
    $result_count[$table] = $total > 0 ? (int) ceil($total / $limit) : 0;
    return array_slice($matched, $limit * ($page - 1), $limit);
}

function update_table(string $table, array $args, string $do = 'insert', $where_id = null) {
    $action = strtolower(trim($do));
    $base = di_csv_table_base($table);

    if (!is_file($base . '.csv')) {
        if ($action === 'delete') return false;
        create_table($table, array_keys($args));
    } elseif ($action !== 'delete') {
        ensure_fields($table, array_keys($args));
    }

    $data = new MyCSV($base);
    $id = false;
    if ($action === 'insert') {
        $data->insert($args);
        $id = $data->insert_id();
    } elseif ($action === 'update') {
        $targetId = $where_id ?? ($args['id'] ?? null);
        if ($targetId !== null && $data->id_exists($targetId)) {
            $data->update($args, $where_id);
            $id = $args['id'] ?? $targetId;
        } else {
            $data->insert($args);
            $id = $data->insert_id();
        }
    } elseif ($action === 'delete') {
        $targetId = $where_id ?? ($args['id'] ?? null);
        if ($targetId !== null) {
            $data->delete($targetId);
            $id = $targetId;
        }
    }
    $data->write();
    $data->close();
    return $id;
}

function multi_update_table(string $table, array $rows, string $action = 'insert'): array {
    if (empty($rows)) return [];
    $action = strtolower(trim($action));
    $allKeys = [];
    foreach ($rows as $r) {
        if (is_array($r)) {
            foreach (array_keys($r) as $k) $allKeys[$k] = true;
        }
    }
    $base = di_csv_table_base($table);
    if (!is_file($base . '.csv')) {
        if ($action === 'delete') return [];
        create_table($table, array_keys($allKeys));
    } elseif ($action !== 'delete') {
        ensure_fields($table, array_keys($allKeys));
    }

    $data = new MyCSV($base);
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if ($action === 'insert') {
            $data->insert($row);
            $ids[] = $data->insert_id();
        } elseif ($action === 'update') {
            $targetId = $row['id'] ?? null;
            if ($targetId !== null && $data->id_exists($targetId)) {
                $data->update($row);
                $ids[] = $targetId;
            } else {
                $data->insert($row);
                $ids[] = $data->insert_id();
            }
        } elseif ($action === 'delete' && isset($row['id'])) {
            $data->delete($row['id']);
            $ids[] = $row['id'];
        }
    }
    $data->write();
    $data->close();
    return $ids;
}

function delete_records(string $table, $where): int {
    if (is_scalar($where)) {
        return update_table($table, ['id' => $where], 'delete') ? 1 : 0;
    }
    if (is_array($where) && isset($where['id']) && count($where) === 1) {
        return update_table($table, ['id' => $where['id']], 'delete') ? 1 : 0;
    }
    if (is_array($where) && !empty($where)) {
        $tableData = get_table($table);
        $toDelete = [];
        foreach ($tableData['data'] as $row) {
            $match = true;
            foreach ($where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) $toDelete[] = ['id' => $row['id']];
        }
        return count(multi_update_table($table, $toDelete, 'delete'));
    }
    return 0;
}

require_once __DIR__ . '/query.php';
require_once __DIR__ . '/common-core.php';
