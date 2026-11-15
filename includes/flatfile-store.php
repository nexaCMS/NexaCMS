<?php
/**
 * DiCimes Structured Flat-File Engine for JSON & PHP Array Backends
 * (includes/flatfile-store.php)
 */

global $flatfile_format;
$flatfile_format = ($flatfile_format === 'array') ? 'array' : 'json';
$GLOBALS['di_db_driver'] = $flatfile_format;

function di_flat_storage_dir(): string {
    global $config, $include_dir, $flatfile_format;
    $baseDir = $include_dir ?? ($config['abs'] ?? dirname(__DIR__));
    $loc = str_replace('\\', '/', trim((string) ($config['database']['location'] ?? 'admin/database')));
    if (preg_match('#^[A-Za-z]:/#', $loc)) {
        $parent = is_dir($loc) ? $loc : (rtrim($baseDir, '/\\') . '/admin/database');
    } else {
        $parent = rtrim($baseDir, '/\\') . '/' . trim($loc, '/');
    }
    $dbName = trim((string) ($config['database']['name'] ?? 'db'), '/\\');
    $dir = rtrim($parent, '/\\') . '/' . $dbName . '_' . $flatfile_format;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function di_flat_table_path(string $table): string {
    global $flatfile_format;
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $table);
    $prefix = (string) ($GLOBALS['nexa_db_table_prefix'] ?? '');
    if ($prefix !== '' && !in_array($clean, ['network_sites', 'network_site_users', 'network_package_requests'], true)) {
        $clean = $prefix . $clean;
    }
    $ext = ($flatfile_format === 'array') ? '.arr.php' : '.json';
    return di_flat_storage_dir() . '/' . $clean . $ext;
}

function di_flat_read(string $table): array {
    global $flatfile_format;
    $path = di_flat_table_path($table);
    if (!is_file($path)) {
        return ['fields' => ['id'], 'next_id' => 1, 'rows' => []];
    }
    if ($flatfile_format === 'array') {
        $payload = include $path;
    } else {
        $payload = json_decode((string) file_get_contents($path), true);
    }
    if (!is_array($payload)) {
        return ['fields' => ['id'], 'next_id' => 1, 'rows' => []];
    }
    return [
        'fields'  => $payload['fields']  ?? ['id'],
        'next_id' => (int) ($payload['next_id'] ?? 1),
        'rows'    => $payload['rows']    ?? [],
    ];
}

function di_flat_write(string $table, array $payload): void {
    global $flatfile_format;
    $path = di_flat_table_path($table);
    if ($flatfile_format === 'array') {
        file_put_contents($path, "<?php\nreturn " . var_export($payload, true) . ";\n", LOCK_EX);
    } else {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

function table_exists(string $table): bool {
    return is_file(di_flat_table_path($table));
}

function list_tables(): array {
    global $flatfile_format;
    $dir = di_flat_storage_dir();
    $ext = ($flatfile_format === 'array') ? '.arr.php' : '.json';
    $tables = [];
    foreach (glob($dir . '/*' . $ext) ?: [] as $f) {
        $tables[] = substr(basename($f), 0, -strlen($ext));
    }
    sort($tables);
    return $tables;
}

function drop_table(string $table): bool {
    $p = di_flat_table_path($table);
    return is_file($p) ? @unlink($p) : true;
}

function create_table(string $table, array $fields): bool {
    if (table_exists($table)) {
        ensure_fields($table, $fields);
        return false;
    }
    $cleanFields = ['id'];
    foreach ($fields as $f) {
        $f = (string) $f;
        if ($f !== '' && strcasecmp($f, 'id') !== 0 && !in_array($f, $cleanFields, true)) {
            $cleanFields[] = $f;
        }
    }
    di_flat_write($table, ['fields' => $cleanFields, 'next_id' => 1, 'rows' => []]);
    return true;
}

function ensure_fields(string $table, array $fields): void {
    if (empty($fields)) return;
    if (!table_exists($table)) {
        create_table($table, $fields);
        return;
    }
    $payload = di_flat_read($table);
    $changed = false;
    foreach ($fields as $f) {
        $f = (string) $f;
        if ($f !== '' && $f !== '*' && !in_array($f, $payload['fields'], true)) {
            $payload['fields'][] = $f;
            foreach ($payload['rows'] as &$r) {
                $r[$f] = $r[$f] ?? '';
            }
            unset($r);
            $changed = true;
        }
    }
    if ($changed) {
        di_flat_write($table, $payload);
    }
}

function get_table(string $table, ?string $sort = null): array {
    $payload = di_flat_read($table);
    $rows = array_values($payload['rows']);
    if ($sort !== null && trim($sort) !== '') {
        $parts = preg_split('/\s+/', trim($sort));
        $col = $parts[0] ?? 'id';
        $desc = (isset($parts[1]) && strtoupper($parts[1]) === 'DESC');
        usort($rows, static function ($a, $b) use ($col, $desc): int {
            $va = $a[$col] ?? '';
            $vb = $b[$col] ?? '';
            $cmp = (is_numeric($va) && is_numeric($vb)) ? ((float) $va <=> (float) $vb) : strnatcasecmp((string) $va, (string) $vb);
            return $desc ? -$cmp : $cmp;
        });
    }
    return ['fields' => $payload['fields'], 'data' => $rows];
}

function has_match($needle, $stack): bool {
    if (is_array($needle)) {
        foreach ($needle as $n) {
            $n = (string) $n;
            if ($n !== '' && preg_match('/' . preg_quote($n, '/') . '/iu', (string) $stack)) return true;
        }
        return false;
    }
    $n = (string) $needle;
    return $n === '' || (bool) preg_match('/' . preg_quote($n, '/') . '/iu', (string) $stack);
}

function count_records(string $table, ?string $field = null, $value = null): int {
    if (!table_exists($table)) return 0;
    $payload = di_flat_read($table);
    if ($field === null && $value === null) return count($payload['rows']);
    $cnt = 0;
    foreach ($payload['rows'] as $r) {
        if ((string) ($r[$field] ?? '') === (string) $value) $cnt++;
    }
    return $cnt;
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
    if (!table_exists($table)) {
        $result_count[$table] = 0;
        return [];
    }
    $payload = di_flat_read($table);
    $tableFields = $payload['fields'];
    $rows = array_values($payload['rows']);
    if ($table === 'posts') {
        $allids = array_column($rows, 'id');
    }
    $selectFields = (empty($fields) || $fields === ['*']) ? $tableFields : $fields;

    if (!empty($sort['by'])) {
        $col = (string) $sort['by'];
        $desc = strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC';
        usort($rows, static function ($a, $b) use ($col, $desc): int {
            $va = $a[$col] ?? '';
            $vb = $b[$col] ?? '';
            $cmp = (is_numeric($va) && is_numeric($vb)) ? ((float) $va <=> (float) $vb) : strnatcasecmp((string) $va, (string) $vb);
            return $desc ? -$cmp : $cmp;
        });
    }

    $searchCols = isset($sort['search'])
        ? (is_array($sort['search']) ? $sort['search'] : [$sort['search']])
        : ($ids !== null ? ['id'] : []);
    $idList = $ids !== null ? (is_array($ids) ? array_map('strval', $ids) : [(string) $ids]) : null;
    $needles = (!$strict && $idList !== null)
        ? array_values(array_filter(explode(' ', strtolower(implode(' ', $idList))), static fn($w) => $w !== ''))
        : [];

    $matched = [];
    foreach ($rows as $row) {
        $keep = ($idList === null);
        if (!$keep) {
            foreach ($searchCols as $col) {
                $cell = (string) ($row[$col] ?? '');
                if (($strict && in_array($cell, $idList, false)) || (!$strict && (empty($needles) || has_match($needles, strtolower($cell))))) {
                    $keep = true;
                    break;
                }
            }
        }
        if ($keep) {
            $proj = [];
            foreach ($selectFields as $f) $proj[$f] = $row[$f] ?? '';
            $matched[] = $proj;
        }
    }

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
    $ids = multi_update_table($table, [$args], $do);
    return $ids[0] ?? false;
}

function multi_update_table(string $table, array $rows, string $action = 'insert'): array {
    if (empty($rows)) return [];
    $action = strtolower(trim($action));
    $allKeys = [];
    foreach ($rows as $r) {
        if (is_array($r)) foreach (array_keys($r) as $k) $allKeys[$k] = true;
    }
    if (!table_exists($table)) {
        if ($action === 'delete') return [];
        create_table($table, array_keys($allKeys));
    } elseif ($action !== 'delete') {
        ensure_fields($table, array_keys($allKeys));
    }

    $payload = di_flat_read($table);
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if ($action === 'delete') {
            $tid = (string) ($row['id'] ?? '');
            if ($tid !== '' && isset($payload['rows'][$tid])) {
                unset($payload['rows'][$tid]);
                $ids[] = $tid;
            }
            continue;
        }
        if ($action === 'update' && isset($row['id']) && isset($payload['rows'][(string) $row['id']])) {
            $tid = (string) $row['id'];
            foreach ($row as $k => $v) {
                $payload['rows'][$tid][$k] = is_scalar($v) || $v === null ? (string) ($v ?? '') : serialize($v);
            }
            $ids[] = $tid;
            continue;
        }
        // Insert
        $tid = (isset($row['id']) && $row['id'] !== '') ? (string) $row['id'] : (string) $payload['next_id'];
        if (is_numeric($tid) && (int) $tid >= $payload['next_id']) {
            $payload['next_id'] = (int) $tid + 1;
        }
        $record = ['id' => $tid];
        foreach ($payload['fields'] as $f) {
            if ($f === 'id') continue;
            $val = $row[$f] ?? '';
            $record[$f] = is_scalar($val) || $val === null ? (string) ($val ?? '') : serialize($val);
        }
        $payload['rows'][$tid] = $record;
        $ids[] = $tid;
    }
    di_flat_write($table, $payload);
    return $ids;
}

function delete_records(string $table, $where): int {
    if (is_scalar($where)) {
        return count(multi_update_table($table, [['id' => $where]], 'delete'));
    }
    if (is_array($where) && !empty($where)) {
        $t = get_table($table);
        $del = [];
        foreach ($t['data'] as $r) {
            $ok = true;
            foreach ($where as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) { $ok = false; break; }
            }
            if ($ok) $del[] = ['id' => $r['id']];
        }
        return count(multi_update_table($table, $del, 'delete'));
    }
    return 0;
}

require_once __DIR__ . '/query.php';
require_once __DIR__ . '/db-migrator.php';
if (($GLOBALS['config']['database']['auto_migrate_from_csv'] ?? true) && !table_exists('settings')) {
    di_migrate_csv_to_active_db();
}
require_once __DIR__ . '/common-core.php';
