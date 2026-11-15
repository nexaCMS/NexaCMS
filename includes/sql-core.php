<?php
/**
 * DiCimes Multi-SQL Storage Core (includes/sql-core.php)
 * Shared by sqlite-core.php, mysql-core.php, and pgsql-core.php.
 */

global $sql_type;
if (empty($sql_type)) {
    $sql_type = strtolower((string) ($GLOBALS['config']['database']['type'] ?? 'sqlite'));
}
$sql_type = match (strtolower($sql_type)) {
    'sqlite', 'sqlite3'               => 'sqlite',
    'mysql', 'mariadb'                => 'mysql',
    'pgsql', 'postgres', 'postgresql' => 'pgsql',
    default                           => 'sqlite',
};

$GLOBALS['di_db_driver'] = $sql_type;
$GLOBALS['di_sql_schema_cache'] = [];

function di_db(?PDO $inject = null): PDO {
    static $pdo = null;
    static $pdoFingerprint = '';
    global $config, $include_dir, $sql_type;
    if ($inject !== null) {
        $pdo = $inject;
        $pdoFingerprint = '';
        $GLOBALS['di_sql_schema_cache'] = [];
        return $pdo;
    }
    // Multisite switches $config['database'] mid-request (a subsite's own
    // SQLite file / database name). If the target changed, drop the cached
    // connection so the next query opens the right database — otherwise a
    // subsite silently reads and writes the MAIN site's tables.
    $fingerprint = md5((string) json_encode([(string) $sql_type, $config['database'] ?? []]));
    if ($pdo !== null && $pdoFingerprint !== $fingerprint) {
        $pdo = null;
        $GLOBALS['di_sql_schema_cache'] = [];
    }
    if ($pdo !== null) {
        return $pdo;
    }
    $pdoFingerprint = $fingerprint;

    $dbCfg = $config['database'] ?? [];

    switch ($sql_type) {
        case 'sqlite':
            if (!empty($dbCfg['file'])) {
                $sqliteFile = str_replace('\\', '/', (string) $dbCfg['file']);
                if (!preg_match('#^([A-Za-z]:/|/)#', $sqliteFile)) {
                    // Relative paths (e.g. a subsite's content/sites/<slug>/
                    // database.sqlite) are anchored to the install root.
                    $sqliteFile = rtrim((string) ($include_dir ?? ($config['abs'] ?? dirname(__DIR__))), '/') . '/' . $sqliteFile;
                }
                $sqliteDir = dirname($sqliteFile);
                if (!is_dir($sqliteDir)) @mkdir($sqliteDir, 0755, true);
            } else {
                $baseDir = $include_dir ?? ($config['abs'] ?? dirname(__DIR__));
                $loc = str_replace('\\', '/', trim((string) ($dbCfg['location'] ?? 'admin/database')));
                if (preg_match('#^[A-Za-z]:/#', $loc)) {
                    $dir = is_dir($loc) ? $loc : (rtrim($baseDir, '/\\') . '/admin/database');
                } else {
                    $dir = rtrim($baseDir, '/\\') . '/' . trim($loc, '/');
                }
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $name = trim((string) ($dbCfg['name'] ?? 'db'), '/\\');
                $sqliteFile = rtrim($dir, '/\\') . '/' . $name . '.sqlite';
            }
            $pdo = new PDO('sqlite:' . $sqliteFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            $pdo->exec('PRAGMA busy_timeout = 5000;');
            break;

        case 'mysql':
            $host = $dbCfg['host'] ?? '127.0.0.1';
            $port = (int) ($dbCfg['port'] ?? 3306);
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($dbCfg['name'] ?? 'dicimes'));
            $user = $dbCfg['user'] ?? 'root';
            $pass = $dbCfg['pass'] ?? '';
            if ($dbCfg['auto_create_db'] ?? true) {
                $initPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $initPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $initPdo = null;
            }
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            break;

        case 'pgsql':
            $host = $dbCfg['host'] ?? '127.0.0.1';
            $port = (int) ($dbCfg['port'] ?? 5432);
            $name = $dbCfg['name'] ?? 'dicimes';
            $user = $dbCfg['user'] ?? 'postgres';
            $pass = $dbCfg['pass'] ?? '';
            $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            break;
    }
    return $pdo;
}

function sql_ident(string $name): string {
    global $sql_type;
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
    return ($sql_type === 'mysql') ? "`{$clean}`" : "\"{$clean}\"";
}

function sql_clean_table_name(string $table): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $table);
}

function sql_get_columns(string $table, bool $refresh = false): array {
    global $sql_type;
    $table = sql_clean_table_name($table);
    if ($table === '') return [];
    // di_db() first: it drops this cache itself when multisite has switched
    // the connection to another database — never serve a stale schema.
    $pdo = di_db();
    if (!$refresh && isset($GLOBALS['di_sql_schema_cache'][$table])) {
        return $GLOBALS['di_sql_schema_cache'][$table];
    }
    $cols = [];
    try {
        if ($sql_type === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . sql_ident($table) . ')');
            foreach ($stmt->fetchAll() as $row) $cols[] = $row['name'];
        } elseif ($sql_type === 'mysql') {
            $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $stmt->execute([$table]);
            foreach ($stmt->fetchAll() as $row) $cols[] = $row['COLUMN_NAME'];
        } elseif ($sql_type === 'pgsql') {
            $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position");
            $stmt->execute([$table]);
            foreach ($stmt->fetchAll() as $row) $cols[] = $row['column_name'];
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    if (!empty($cols)) {
        $GLOBALS['di_sql_schema_cache'][$table] = $cols;
    } else {
        unset($GLOBALS['di_sql_schema_cache'][$table]);
    }
    return $cols;
}

function table_exists(string $table): bool {
    return !empty(sql_get_columns($table));
}

function list_tables(): array {
    global $sql_type;
    $pdo = di_db();
    $tables = [];
    if ($sql_type === 'sqlite') {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strval', $rows);
    } elseif ($sql_type === 'mysql') {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strval', $rows);
    } elseif ($sql_type === 'pgsql') {
        $rows = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strval', $rows);
    }
    sort($tables);
    return $tables;
}

function drop_table(string $table): bool {
    $table = sql_clean_table_name($table);
    if ($table === '') return false;
    di_db()->exec('DROP TABLE IF EXISTS ' . sql_ident($table));
    unset($GLOBALS['di_sql_schema_cache'][$table]);
    return true;
}

function create_table(string $table, array $fields): bool {
    global $sql_type;
    $table = sql_clean_table_name($table);
    if ($table === '') return false;
    if (table_exists($table)) {
        ensure_fields($table, $fields);
        return false;
    }
    $pkDef = match ($sql_type) {
        'mysql' => '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'pgsql' => '"id" SERIAL PRIMARY KEY',
        default => '"id" INTEGER PRIMARY KEY AUTOINCREMENT',
    };
    $colType = ($sql_type === 'mysql') ? 'LONGTEXT NULL' : 'TEXT';
    $defs = [$pkDef];
    $seen = ['id' => true];
    foreach ($fields as $f) {
        $cleanF = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $f);
        $lower = strtolower($cleanF);
        if ($cleanF === '' || isset($seen[$lower])) continue;
        $seen[$lower] = true;
        $defs[] = sql_ident($cleanF) . ' ' . $colType;
    }
    $sql = 'CREATE TABLE IF NOT EXISTS ' . sql_ident($table) . ' (' . implode(', ', $defs) . ')';
    if ($sql_type === 'mysql') {
        $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
    di_db()->exec($sql);
    sql_get_columns($table, true);
    return true;
}

function ensure_fields(string $table, array $fields): void {
    global $sql_type;
    $table = sql_clean_table_name($table);
    if ($table === '' || empty($fields)) return;
    $existing = sql_get_columns($table);
    if (empty($existing)) {
        create_table($table, $fields);
        return;
    }
    $existingLower = array_map('strtolower', $existing);
    $colType = ($sql_type === 'mysql') ? 'LONGTEXT NULL' : 'TEXT';
    $qTable = sql_ident($table);
    $added = false;
    foreach ($fields as $f) {
        $cleanF = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $f);
        if ($cleanF === '' || $cleanF === '*' || in_array(strtolower($cleanF), $existingLower, true)) continue;
        di_db()->exec("ALTER TABLE {$qTable} ADD COLUMN " . sql_ident($cleanF) . " {$colType}");
        $existingLower[] = strtolower($cleanF);
        $added = true;
    }
    if ($added) sql_get_columns($table, true);
}

function get_table(string $table, ?string $sort = null): array {
    $table = sql_clean_table_name($table);
    $fields = sql_get_columns($table);
    if (empty($fields)) return ['fields' => ['id'], 'data' => []];
    $qTable = sql_ident($table);
    $orderSql = '';
    if ($sort !== null && trim($sort) !== '') {
        $parts = preg_split('/\s+/', trim(str_replace([',', '`', '"', "'"], ' ', $sort)));
        $col = $parts[0] ?? 'id';
        $dir = (isset($parts[1]) && strtoupper($parts[1]) === 'DESC') ? 'DESC' : 'ASC';
        if (in_array($col, $fields, true)) {
            $orderSql = ' ORDER BY ' . sql_ident($col) . ' ' . $dir;
        }
    }
    $rows = di_db()->query("SELECT * FROM {$qTable}{$orderSql}")->fetchAll();
    foreach ($rows as &$r) {
        foreach ($r as $k => $v) if ($v === null) $r[$k] = '';
    }
    unset($r);
    return ['fields' => $fields, 'data' => $rows];
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
    $table = sql_clean_table_name($table);
    $cols = sql_get_columns($table);
    if (empty($cols)) return 0;
    $qTable = sql_ident($table);
    if ($field === null && $value === null) {
        return (int) di_db()->query("SELECT COUNT(*) FROM {$qTable}")->fetchColumn();
    }
    if (!in_array($field, $cols, true)) return 0;
    $stmt = di_db()->prepare("SELECT COUNT(*) FROM {$qTable} WHERE " . sql_ident($field) . ' = ?');
    $stmt->execute([(string) $value]);
    return (int) $stmt->fetchColumn();
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
    $table = sql_clean_table_name($table);
    $tableFields = sql_get_columns($table);
    if (empty($tableFields)) {
        $result_count[$table] = 0;
        return [];
    }
    $pdo = di_db();
    $qTable = sql_ident($table);
    if ($table === 'posts') {
        $allids = $pdo->query('SELECT ' . sql_ident('id') . " FROM {$qTable}")->fetchAll(PDO::FETCH_COLUMN);
    }
    if (empty($fields) || $fields === ['*']) {
        $selectCols = $tableFields;
    } else {
        $selectCols = array_values(array_filter($fields, static fn($f) => in_array($f, $tableFields, true)));
        if (empty($selectCols)) $selectCols = $tableFields;
    }
    $selectSql = implode(', ', array_map('sql_ident', $selectCols));

    $whereClauses = [];
    $params = [];
    $searchCols = isset($sort['search'])
        ? (is_array($sort['search']) ? $sort['search'] : [$sort['search']])
        : ($ids !== null ? ['id'] : []);
    $searchCols = array_values(array_filter($searchCols, static fn($c) => in_array($c, $tableFields, true)));

    if ($ids !== null && !empty($searchCols)) {
        $idList = is_array($ids) ? array_values(array_map('strval', $ids)) : [(string) $ids];
        if ($strict) {
            $placeholders = implode(', ', array_fill(0, count($idList), '?'));
            $orParts = [];
            foreach ($searchCols as $col) {
                $orParts[] = sql_ident($col) . " IN ({$placeholders})";
                foreach ($idList as $v) $params[] = $v;
            }
            $whereClauses[] = '(' . implode(' OR ', $orParts) . ')';
        } else {
            $words = array_values(array_filter(explode(' ', strtolower(implode(' ', $idList))), static fn($w) => $w !== ''));
            if (!empty($words)) {
                $orParts = [];
                foreach ($searchCols as $col) {
                    $qCol = sql_ident($col);
                    foreach ($words as $w) {
                        $orParts[] = "LOWER({$qCol}) LIKE ?";
                        $params[] = '%' . $w . '%';
                    }
                }
                $whereClauses[] = '(' . implode(' OR ', $orParts) . ')';
            }
        }
    }

    $whereSql = !empty($whereClauses) ? (' WHERE ' . implode(' AND ', $whereClauses)) : '';
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM {$qTable}{$whereSql}");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();

    $orderSql = '';
    $byCol = (string) ($sort['by'] ?? 'id');
    if (in_array($byCol, $tableFields, true)) {
        $dir = strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $orderSql = ' ORDER BY ' . sql_ident($byCol) . ' ' . $dir;
    }

    $page = max(1, (int) $page);
    if ($limit === -1 || $limit <= 0) {
        $result_count[$table] = $total > 0 ? 1 : 0;
        $stmt = $pdo->prepare("SELECT {$selectSql} FROM {$qTable}{$whereSql}{$orderSql}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        $result_count[$table] = $total > 0 ? (int) ceil($total / $limit) : 0;
        $offset = $limit * ($page - 1);
        $stmt = $pdo->prepare("SELECT {$selectSql} FROM {$qTable}{$whereSql}{$orderSql} LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }

    foreach ($rows as &$r) {
        foreach ($r as $k => $v) if ($v === null) $r[$k] = '';
    }
    unset($r);
    return $rows;
}

function di_sql_apply_row(PDO $pdo, string $table, array $args, string $action, $where_id = null) {
    global $sql_type;
    $qTable = sql_ident($table);
    if ($action === 'delete') {
        $targetId = $where_id ?? ($args['id'] ?? null);
        if ($targetId === null) return false;
        $stmt = $pdo->prepare("DELETE FROM {$qTable} WHERE " . sql_ident('id') . ' = ?');
        $stmt->execute([$targetId]);
        return $targetId;
    }

    $cleanArgs = [];
    foreach ($args as $k => $v) {
        $ck = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k);
        if ($ck !== '') {
            $cleanArgs[$ck] = is_scalar($v) || $v === null ? (string) ($v ?? '') : serialize($v);
        }
    }

    if ($action === 'update') {
        $targetId = $where_id ?? ($cleanArgs['id'] ?? null);
        if ($targetId !== null && $targetId !== '') {
            $chk = $pdo->prepare("SELECT 1 FROM {$qTable} WHERE " . sql_ident('id') . ' = ?');
            $chk->execute([$targetId]);
            if ($chk->fetchColumn()) {
                $updateCols = $cleanArgs;
                if ($where_id === null) unset($updateCols['id']);
                if (empty($updateCols)) return $targetId;
                $setParts = [];
                $vals = [];
                foreach ($updateCols as $col => $val) {
                    $setParts[] = sql_ident($col) . ' = ?';
                    $vals[] = $val;
                }
                $vals[] = $targetId;
                $stmt = $pdo->prepare("UPDATE {$qTable} SET " . implode(', ', $setParts) . ' WHERE ' . sql_ident('id') . ' = ?');
                $stmt->execute($vals);
                return $cleanArgs['id'] ?? $targetId;
            }
        }
    }

    $explicitId = isset($cleanArgs['id']) && $cleanArgs['id'] !== '' && is_numeric($cleanArgs['id']);
    if (!$explicitId) unset($cleanArgs['id']);

    if (empty($cleanArgs)) {
        if ($sql_type === 'pgsql') {
            return $pdo->query("INSERT INTO {$qTable} DEFAULT VALUES RETURNING \"id\"")->fetchColumn();
        }
        $pdo->exec("INSERT INTO {$qTable} (" . sql_ident('id') . ') VALUES (NULL)');
        return $pdo->lastInsertId();
    }

    $cols = array_keys($cleanArgs);
    $vals = array_values($cleanArgs);
    $colSql = implode(', ', array_map('sql_ident', $cols));
    $phSql = implode(', ', array_fill(0, count($vals), '?'));

    if ($sql_type === 'pgsql') {
        $stmt = $pdo->prepare("INSERT INTO {$qTable} ({$colSql}) VALUES ({$phSql}) RETURNING \"id\"");
        $stmt->execute($vals);
        $newId = $stmt->fetchColumn();
        if ($explicitId) {
            $pdo->exec("SELECT setval(pg_get_serial_sequence('\"{$table}\"', 'id'), COALESCE((SELECT MAX(\"id\") FROM {$qTable}), 1), true)");
        }
        return $newId;
    }

    $stmt = $pdo->prepare("INSERT INTO {$qTable} ({$colSql}) VALUES ({$phSql})");
    $stmt->execute($vals);
    return $explicitId ? $cleanArgs['id'] : $pdo->lastInsertId();
}

function update_table(string $table, array $args, string $do = 'insert', $where_id = null) {
    $table = sql_clean_table_name($table);
    $action = strtolower(trim($do));
    if ($table === '') return false;
    if ($action === 'delete') {
        if (!table_exists($table)) return false;
    } else {
        if (!table_exists($table)) create_table($table, array_keys($args));
        else ensure_fields($table, array_keys($args));
    }
    return di_sql_apply_row(di_db(), $table, $args, $action, $where_id);
}

function multi_update_table(string $table, array $rows, string $action = 'insert'): array {
    $table = sql_clean_table_name($table);
    $action = strtolower(trim($action));
    if ($table === '' || empty($rows)) return [];
    $allKeys = [];
    foreach ($rows as $r) {
        if (is_array($r)) foreach (array_keys($r) as $k) $allKeys[$k] = true;
    }
    if ($action === 'delete') {
        if (!table_exists($table)) return [];
    } else {
        if (!table_exists($table)) create_table($table, array_keys($allKeys));
        else ensure_fields($table, array_keys($allKeys));
    }
    $pdo = di_db();
    $ids = [];
    $inTx = $pdo->inTransaction();
    if (!$inTx) $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $res = di_sql_apply_row($pdo, $table, $row, $action);
            if ($res !== false) $ids[] = $res;
        }
        if (!$inTx) $pdo->commit();
    } catch (Throwable $e) {
        if (!$inTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $ids;
}

function delete_records(string $table, $where): int {
    $table = sql_clean_table_name($table);
    $cols = sql_get_columns($table);
    if (empty($cols)) return 0;
    $qTable = sql_ident($table);
    $pdo = di_db();
    if (is_scalar($where)) {
        $stmt = $pdo->prepare("DELETE FROM {$qTable} WHERE " . sql_ident('id') . ' = ?');
        $stmt->execute([$where]);
        return $stmt->rowCount();
    }
    if (is_array($where) && !empty($where)) {
        $clauses = [];
        $params = [];
        foreach ($where as $k => $v) {
            if (in_array($k, $cols, true)) {
                $clauses[] = sql_ident($k) . ' = ?';
                $params[] = (string) $v;
            }
        }
        if (empty($clauses)) return 0;
        $stmt = $pdo->prepare("DELETE FROM {$qTable} WHERE " . implode(' AND ', $clauses));
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    return 0;
}

function di_sql_normalize_raw_sql(string $sql): string {
    global $sql_type;
    $out = '';
    $len = strlen($sql);
    $inSingle = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inSingle) {
            $out .= $ch;
            if ($ch === '\\' && $i + 1 < $len) $out .= $sql[++$i];
            elseif ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") $out .= $sql[++$i];
                else $inSingle = false;
            }
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $out .= $ch;
        } elseif ($ch === '`' && $sql_type !== 'mysql') {
            $out .= '"';
        } else {
            $out .= $ch;
        }
    }
    if ($sql_type === 'pgsql') {
        $out = preg_replace('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*;?\s*$/i', 'LIMIT $2 OFFSET $1', $out);
    }
    if (preg_match('/^\s*CREATE\s+TABLE\b/i', $out)) {
        if ($sql_type === 'sqlite') {
            $out = preg_replace('/\bINT(?:EGER)?\s+(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $out);
            $out = preg_replace('/\bINT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $out);
            $out = preg_replace('/\s*ENGINE\s*=\s*\w+[^;]*$/i', '', $out);
        } elseif ($sql_type === 'pgsql') {
            $out = preg_replace('/\bINT(?:EGER)?\s+(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'SERIAL PRIMARY KEY', $out);
            $out = preg_replace('/\bINT(?:EGER)?\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', 'SERIAL PRIMARY KEY', $out);
            $out = preg_replace('/\s*ENGINE\s*=\s*\w+[^;]*$/i', '', $out);
        }
    }
    return $out;
}

function query(string $query = '', array $params = []) {
    global $sql_type;
    $trimmed = rtrim(trim($query), "; \t\n\r\0\x0B");
    if ($trimmed === '') return [];

    if (preg_match('/^SHOW\s+TABLES$/i', $trimmed)) {
        return array_map(static fn($t) => ['table' => $t], list_tables());
    }

    if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?[`"\']?([\w\-]+)[`"\']?$/i', $trimmed, $tm)) {
        $tbl = sql_clean_table_name($tm[1]);
        if (!table_exists($tbl)) return false;
        $qTbl = sql_ident($tbl);
        if ($sql_type === 'sqlite') {
            di_db()->exec("DELETE FROM {$qTbl}");
            try { di_db()->exec("DELETE FROM sqlite_sequence WHERE name = " . di_db()->quote($tbl)); } catch (Throwable $e) {}
        } elseif ($sql_type === 'pgsql') {
            di_db()->exec("TRUNCATE TABLE {$qTbl} RESTART IDENTITY");
        } else {
            di_db()->exec("TRUNCATE TABLE {$qTbl}");
        }
        return true;
    }

    if (preg_match('/^(?:DESCRIBE|DESC|SHOW\s+COLUMNS\s+FROM)\s+[`"\']?([\w\-]+)[`"\']?$/i', $trimmed, $m)) {
        $cols = sql_get_columns($m[1]);
        $ret = ['fields' => [], 'type' => [], 'null' => [], 'key' => [], 'default' => [], 'extra' => []];
        foreach ($cols as $k) {
            $ret['fields'][]  = $k;
            $ret['type'][]    = ($k === 'id') ? 'int' : 'text';
            $ret['null'][]    = ($k === 'id') ? 'NO' : 'YES';
            $ret['key'][]     = ($k === 'id') ? 'PRI' : '';
            $ret['default'][] = 'NULL';
            $ret['extra'][]   = ($k === 'id') ? 'auto_increment' : '';
        }
        return $ret;
    }

    $normalized = di_sql_normalize_raw_sql($trimmed);
    $pdo = di_db();
    $verb = strtolower(strtok($normalized, " \t\n\r") ?: '');

    if ($verb === 'insert' && $sql_type === 'pgsql' && !preg_match('/\bRETURNING\b/i', $normalized)) {
        $stmt = $pdo->prepare($normalized . ' RETURNING "id"');
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare($normalized);
    $stmt->execute($params);

    if ($verb === 'select' || $verb === 'pragma' || $verb === 'with') {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach ($r as $k => $v) if ($v === null) $r[$k] = '';
        }
        unset($r);
        return $rows;
    }
    if ($verb === 'insert' || $verb === 'replace') return $pdo->lastInsertId();
    if (in_array($verb, ['create', 'alter', 'drop', 'truncate'], true)) {
        $GLOBALS['di_sql_schema_cache'] = [];
        return true;
    }
    return $stmt->rowCount();
}

require_once __DIR__ . '/db-migrator.php';
if (($GLOBALS['config']['database']['auto_migrate_from_csv'] ?? true) && !table_exists('settings')) {
    di_migrate_csv_to_active_db();
}
require_once __DIR__ . '/common-core.php';
