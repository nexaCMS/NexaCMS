<?php
/**
 * DiCimes Universal Flat-File SQL Engine (includes/query.php)
 *
 * Allows plugins & core code to write standard SQL once via query($sql, $params)
 * and transparently translates it to CSV, PHP Array, or JSON storage operations,
 * returning results in the exact same structure as native PDO SQL (SQLite/MySQL/PostgreSQL).
 *
 * Supported SQL Statements:
 *   - SELECT [DISTINCT] cols/aggregates [AS alias]
 *       FROM table [alias]
 *       [[INNER|LEFT|RIGHT] [OUTER] JOIN table2 [alias2] ON a.col = b.col ...]
 *       [WHERE expr (AND, OR, NOT, =, !=, <>, <, <=, >, >=, [NOT] LIKE, [NOT] IN, [NOT] BETWEEN, IS [NOT] NULL, (...))]
 *       [GROUP BY cols]
 *       [HAVING expr]
 *       [ORDER BY col [ASC|DESC], ...]
 *       [LIMIT count | LIMIT offset, count | LIMIT count OFFSET offset]
 *   - INSERT INTO table [(cols)] VALUES (...), (...)
 *   - REPLACE INTO table [(cols)] VALUES (...), (...)
 *   - UPDATE table SET col = val | col = col +/- N, ... [WHERE expr]
 *   - DELETE FROM table [WHERE expr]
 *   - CREATE TABLE [IF NOT EXISTS] table (...)
 *   - ALTER TABLE table ADD [COLUMN] col [TYPE]
 *   - TRUNCATE [TABLE] table
 *   - DROP TABLE [IF EXISTS] table
 *   - DESCRIBE / DESC / SHOW COLUMNS FROM table
 *   - SHOW TABLES
 *   - Positional (?) and named (:key) prepared parameter bindings
 */

if (!function_exists('rearrangeData')) {
    function rearrangeData(array $inputArray): array {
        $outputArray = [];
        foreach ($inputArray as $field => $values) {
            foreach ((array) $values as $id => $value) {
                $outputArray[$id][$field] = $value;
            }
        }
        return $outputArray;
    }
}

/* ==========================================================================
 * 1. PARAMETER BINDING & QUOTE / CSV TOKENIZATION
 * ========================================================================== */

function di_sql_bind_params(string $sql, array $params = []): string {
    if (empty($params)) {
        return $sql;
    }
    $isNamed = false;
    foreach (array_keys($params) as $k) {
        if (is_string($k)) {
            $isNamed = true;
            break;
        }
    }
    $quoteVal = static function ($val): string {
        if ($val === null) return 'NULL';
        if (is_bool($val)) return $val ? '1' : '0';
        if (is_int($val) || is_float($val)) return (string) $val;
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $val) . "'";
    };
    if ($isNamed) {
        foreach ($params as $k => $v) {
            $placeholder = str_starts_with((string) $k, ':') ? (string) $k : (':' . $k);
            $sql = preg_replace('/' . preg_quote($placeholder, '/') . '\b/', $quoteVal($v), $sql);
        }
        return $sql;
    }
    $pos = 0;
    $vals = array_values($params);
    $out = '';
    $len = strlen($sql);
    $inQuote = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inQuote !== null) {
            $out .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $sql[++$i];
            } elseif ($ch === $inQuote) {
                if ($i + 1 < $len && $sql[$i + 1] === $inQuote) {
                    $out .= $sql[++$i];
                } else {
                    $inQuote = null;
                }
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inQuote = $ch;
            $out .= $ch;
        } elseif ($ch === '?' && array_key_exists($pos, $vals)) {
            $out .= $quoteVal($vals[$pos++]);
        } else {
            $out .= $ch;
        }
    }
    return $out;
}

function di_sql_strip_quotes(string $token): string {
    $token = trim($token);
    if (strlen($token) >= 2) {
        $f = $token[0];
        $l = $token[strlen($token) - 1];
        if (($f === "'" && $l === "'") || ($f === '"' && $l === '"') || ($f === '`' && $l === '`')) {
            $inner = substr($token, 1, -1);
            $inner = str_replace(["\\'", '\\"', "''", '""'], ["'", '"', "'", '"'], $inner);
            return stripcslashes($inner);
        }
    }
    return $token;
}

function di_sql_split_csv_outside_quotes(string $str): array {
    $parts = [];
    $buf = '';
    $len = strlen($str);
    $inQuote = null;
    $depth = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = $str[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $str[++$i];
            } elseif ($ch === $inQuote) {
                if ($i + 1 < $len && $str[$i + 1] === $inQuote) {
                    $buf .= $str[++$i];
                } else {
                    $inQuote = null;
                }
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inQuote = $ch;
            $buf .= $ch;
        } elseif ($ch === '(') {
            $depth++;
            $buf .= $ch;
        } elseif ($ch === ')') {
            $depth = max(0, $depth - 1);
            $buf .= $ch;
        } elseif ($ch === ',' && $depth === 0) {
            $parts[] = trim($buf);
            $buf = '';
        } else {
            $buf .= $ch;
        }
    }
    if (trim($buf) !== '') {
        $parts[] = trim($buf);
    }
    return $parts;
}

/* ==========================================================================
 * 2. WHERE / HAVING EXPRESSION TOKENIZER & EVALUATOR
 * ========================================================================== */

function di_sql_tokenize_where(string $expr): array {
    $tokens = [];
    $len = strlen($expr);
    $i = 0;
    while ($i < $len) {
        if (ctype_space($expr[$i])) {
            $i++;
            continue;
        }
        $ch = $expr[$i];
        if ($ch === '(' || $ch === ')') {
            $tokens[] = ['type' => 'PAREN', 'val' => $ch];
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $q = $ch;
            $buf = $ch;
            $i++;
            while ($i < $len) {
                $c = $expr[$i];
                $buf .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $expr[++$i];
                } elseif ($c === $q) {
                    if ($i + 1 < $len && $expr[$i + 1] === $q) {
                        $buf .= $expr[++$i];
                    } else {
                        $i++;
                        break;
                    }
                }
                $i++;
            }
            $tokens[] = ['type' => ($q === '`' ? 'IDENT' : 'STR'), 'val' => di_sql_strip_quotes($buf)];
            continue;
        }
        $two = substr($expr, $i, 2);
        if (in_array($two, ['!=', '<>', '>=', '<=', '==', '&&', '||'], true)) {
            if ($two === '&&') {
                $tokens[] = ['type' => 'WORD', 'val' => 'AND', 'upper' => 'AND'];
            } elseif ($two === '||') {
                $tokens[] = ['type' => 'WORD', 'val' => 'OR', 'upper' => 'OR'];
            } else {
                $tokens[] = ['type' => 'OP', 'val' => ($two === '==' ? '=' : ($two === '<>' ? '!=' : $two))];
            }
            $i += 2;
            continue;
        }
        if (in_array($ch, ['=', '>', '<', ','], true)) {
            $tokens[] = ['type' => ($ch === ',' ? 'COMMA' : 'OP'), 'val' => $ch];
            $i++;
            continue;
        }
        $buf = '';
        while ($i < $len && !ctype_space($expr[$i]) && !in_array($expr[$i], ['(', ')', '=', '!', '<', '>', ',', "'", '"', '`', '&', '|'], true)) {
            $buf .= $expr[$i++];
        }
        // Support aggregate calls inside HAVING like COUNT(*) >= 2
        if ($i < $len && $expr[$i] === '(' && in_array(strtoupper($buf), ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)) {
            $depth = 0;
            while ($i < $len) {
                $c = $expr[$i++];
                $buf .= $c;
                if ($c === '(') $depth++;
                elseif ($c === ')') {
                    $depth--;
                    if ($depth <= 0) break;
                }
            }
        }
        $upper = strtoupper($buf);
        $tokens[] = ['type' => 'WORD', 'val' => $buf, 'upper' => $upper];
    }
    return $tokens;
}

function di_sql_lookup_row_col(array $row, string $colRef) {
    $colRef = trim($colRef, " `'\"");
    if (array_key_exists($colRef, $row)) {
        return $row[$colRef];
    }
    if (str_contains($colRef, '.')) {
        $bare = explode('.', $colRef, 2)[1];
        $bare = trim($bare, " `'\"");
        if (array_key_exists($colRef, $row)) return $row[$colRef];
        if (array_key_exists($bare, $row)) return $row[$bare];
    }
    // Case-insensitive fallback
    $lower = strtolower($colRef);
    foreach ($row as $k => $v) {
        if (strtolower((string) $k) === $lower) return $v;
    }
    return null;
}

function di_sql_like_match(string $value, string $pattern): bool {
    if (!str_contains($pattern, '%') && !str_contains($pattern, '_')) {
        return strcasecmp($value, $pattern) === 0;
    }
    $regex = '/^';
    $len = strlen($pattern);
    for ($i = 0; $i < $len; $i++) {
        $c = $pattern[$i];
        if ($c === '%') $regex .= '.*';
        elseif ($c === '_') $regex .= '.';
        else $regex .= preg_quote($c, '/');
    }
    $regex .= '$/isu';
    return (bool) preg_match($regex, $value);
}

function di_sql_eval_where(array $row, array $tokens): bool {
    if (empty($tokens)) return true;
    $pos = 0;
    $count = count($tokens);

    $parseOr = static function () use (&$parseOr, &$parseAnd, &$pos, $count, $tokens): bool {
        $left = $parseAnd();
        while ($pos < $count && ($tokens[$pos]['upper'] ?? '') === 'OR') {
            $pos++;
            $right = $parseAnd();
            $left = $left || $right;
        }
        return $left;
    };
    $parseAnd = static function () use (&$parsePrimary, &$pos, $count, $tokens): bool {
        $left = $parsePrimary();
        while ($pos < $count && ($tokens[$pos]['upper'] ?? '') === 'AND') {
            $pos++;
            $right = $parsePrimary();
            $left = $left && $right;
        }
        return $left;
    };
    $resolveValue = static function (array $tok, array $row, bool $preferColumn) {
        if ($tok['type'] === 'STR') return $tok['val'];
        if ($tok['type'] === 'IDENT') {
            return di_sql_lookup_row_col($row, $tok['val']);
        }
        $raw = $tok['val'];
        if (($tok['upper'] ?? '') === 'NULL') return null;
        if (!$preferColumn && is_numeric($raw)) return $raw;
        $found = di_sql_lookup_row_col($row, $raw);
        if ($found !== null) return $found;
        return $preferColumn ? null : $raw;
    };
    $parsePrimary = static function () use (&$parseOr, &$parsePrimary, $resolveValue, &$pos, $count, $tokens, $row): bool {
        if ($pos >= $count) return true;
        if (($tokens[$pos]['upper'] ?? '') === 'NOT') {
            $pos++;
            return !$parsePrimary();
        }
        if ($tokens[$pos]['type'] === 'PAREN' && $tokens[$pos]['val'] === '(') {
            $pos++;
            $val = $parseOr();
            if ($pos < $count && $tokens[$pos]['type'] === 'PAREN' && $tokens[$pos]['val'] === ')') $pos++;
            return $val;
        }
        $lhsTok = $tokens[$pos++];
        $lhs = $resolveValue($lhsTok, $row, true);
        if ($pos >= $count) return (bool) $lhs;

        if (($tokens[$pos]['upper'] ?? '') === 'IS') {
            $pos++;
            $not = false;
            if ($pos < $count && ($tokens[$pos]['upper'] ?? '') === 'NOT') {
                $not = true;
                $pos++;
            }
            if ($pos < $count) $pos++;
            $isNull = ($lhs === null || $lhs === '');
            return $not ? !$isNull : $isNull;
        }

        $negateOp = false;
        if (($tokens[$pos]['upper'] ?? '') === 'NOT') {
            $negateOp = true;
            $pos++;
        }

        if ($pos < $count && ($tokens[$pos]['upper'] ?? '') === 'BETWEEN') {
            $pos++;
            $low  = $pos < $count ? $resolveValue($tokens[$pos++], $row, false) : '';
            if ($pos < $count && ($tokens[$pos]['upper'] ?? '') === 'AND') $pos++;
            $high = $pos < $count ? $resolveValue($tokens[$pos++], $row, false) : '';
            if ($lhs === null) return false;
            $allNum = is_numeric($lhs) && is_numeric($low) && is_numeric($high);
            $inRange = $allNum
                ? ((float) $lhs >= (float) $low && (float) $lhs <= (float) $high)
                : ((string) $lhs >= (string) $low && (string) $lhs <= (string) $high);
            return $negateOp ? !$inRange : $inRange;
        }

        if ($pos < $count && ($tokens[$pos]['upper'] ?? '') === 'IN') {
            $pos++;
            $list = [];
            if ($pos < $count && $tokens[$pos]['type'] === 'PAREN' && $tokens[$pos]['val'] === '(') {
                $pos++;
                while ($pos < $count && !($tokens[$pos]['type'] === 'PAREN' && $tokens[$pos]['val'] === ')')) {
                    if ($tokens[$pos]['type'] !== 'COMMA') {
                        $list[] = (string) $resolveValue($tokens[$pos], $row, false);
                    }
                    $pos++;
                }
                if ($pos < $count) $pos++;
            }
            if ($lhs === null) return false;
            $in = in_array((string) $lhs, $list, false);
            return $negateOp ? !$in : $in;
        }

        if ($pos < $count && in_array($tokens[$pos]['upper'] ?? '', ['LIKE', 'ILIKE'], true)) {
            $pos++;
            $pat = $pos < $count ? (string) $resolveValue($tokens[$pos++], $row, false) : '';
            if ($lhs === null) return false;
            $matched = di_sql_like_match((string) $lhs, $pat);
            return $negateOp ? !$matched : $matched;
        }

        if ($tokens[$pos]['type'] === 'OP') {
            $op = $tokens[$pos++]['val'];
            $rhs = $pos < $count ? $resolveValue($tokens[$pos++], $row, false) : '';
            if ($lhs === null || $rhs === null) return false;
            $bothNum = is_numeric($lhs) && is_numeric($rhs);
            $lCmp = $bothNum ? (float) $lhs : (string) $lhs;
            $rCmp = $bothNum ? (float) $rhs : (string) $rhs;
            return match ($op) {
                '='  => $bothNum ? ($lCmp == $rCmp) : ((string) $lhs === (string) $rhs),
                '!=' => $bothNum ? ($lCmp != $rCmp) : ((string) $lhs !== (string) $rhs),
                '>'  => $lCmp > $rCmp,
                '<'  => $lCmp < $rCmp,
                '>=' => $lCmp >= $rCmp,
                '<=' => $lCmp <= $rCmp,
                default => false,
            };
        }
        return (bool) $lhs;
    };
    return $parseOr();
}

/* ==========================================================================
 * 3. MAIN DISPATCHER: query($sql, $params)
 * ========================================================================== */

function query(string $query = '', array $params = []) {
    $trimmed = rtrim(trim($query), "; \t\n\r\0\x0B");
    if ($trimmed === '') return [];

    $bound = di_sql_bind_params($trimmed, $params);
    if (preg_match('/^(SELECT|INSERT|REPLACE|UPDATE|DELETE|CREATE|ALTER|TRUNCATE|DROP|DESCRIBE|DESC|SHOW)\b/i', $bound, $m)) {
        $verb = strtolower($m[1]);
        return match ($verb) {
            'select'                   => di_query_select($bound),
            'insert'                   => di_query_insert($bound, false),
            'replace'                  => di_query_insert($bound, true),
            'update'                   => di_query_update($bound),
            'delete'                   => di_query_delete($bound),
            'create'                   => di_query_create($bound),
            'alter'                    => di_query_alter($bound),
            'truncate'                 => di_query_truncate($bound),
            'drop'                     => di_query_drop($bound),
            'describe', 'desc', 'show' => di_query_describe($bound),
        };
    }
    return false;
}

/* ==========================================================================
 * 4. SELECT (WITH JOINS, ALIASES, AGGREGATES, GROUP BY, HAVING, ORDER, LIMIT)
 * ========================================================================== */

function di_sql_split_select_clauses(string $sql): ?array {
    // Strip leading SELECT [DISTINCT]
    if (!preg_match('/^SELECT\s+(DISTINCT\s+)?(.*)$/is', trim($sql), $m)) {
        return null;
    }
    $isDistinct = !empty($m[1]);
    $rest = $m[2];

    // Scan top-level (outside quotes and parens) for clause boundaries
    $clauses = [
        'select' => '',
        'from'   => null,
        'where'  => null,
        'group'  => null,
        'having' => null,
        'order'  => null,
        'limit'  => null,
    ];
    $current = 'select';
    $len = strlen($rest);
    $inQuote = null;
    $depth = 0;
    $i = 0;
    $buf = '';

    $keywords = [
        'FROM'     => 'from',
        'WHERE'    => 'where',
        'GROUP BY' => 'group',
        'HAVING'   => 'having',
        'ORDER BY' => 'order',
        'LIMIT'    => 'limit',
    ];

    while ($i < $len) {
        $ch = $rest[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) $buf .= $rest[++$i];
            elseif ($ch === $inQuote) {
                if ($i + 1 < $len && $rest[$i + 1] === $inQuote) $buf .= $rest[++$i];
                else $inQuote = null;
            }
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inQuote = $ch;
            $buf .= $ch;
            $i++;
            continue;
        }
        if ($ch === '(') { $depth++; $buf .= $ch; $i++; continue; }
        if ($ch === ')') { $depth = max(0, $depth - 1); $buf .= $ch; $i++; continue; }

        if ($depth === 0 && ($i === 0 || ctype_space($rest[$i - 1]))) {
            $matchedKw = null;
            $matchedLen = 0;
            foreach ($keywords as $kw => $slot) {
                $pattern = '/^' . str_replace(' ', '\s+', $kw) . '(?=\s|$)/i';
                if (preg_match($pattern, substr($rest, $i), $km)) {
                    $matchedKw = $slot;
                    $matchedLen = strlen($km[0]);
                    break;
                }
            }
            if ($matchedKw !== null) {
                $clauses[$current] = trim($buf);
                $buf = '';
                $current = $matchedKw;
                $i += $matchedLen;
                continue;
            }
        }
        $buf .= $ch;
        $i++;
    }
    $clauses[$current] = trim($buf);
    $clauses['distinct'] = $isDistinct;
    return $clauses;
}

function di_sql_prefix_row(array $row, string $alias, array $fields, bool $useNull = false): array {
    $out = [];
    foreach ($fields as $f) {
        $val = $useNull ? null : ($row[$f] ?? '');
        $out["{$alias}.{$f}"] = $val;
        if (!array_key_exists($f, $out)) {
            $out[$f] = $val;
        }
    }
    return $out;
}

function di_sql_load_from_and_joins(string $fromClause): ?array {
    // Split into base table + optional JOIN segments
    $joinSplitRegex = '/\b((?:INNER\s+|LEFT\s+(?:OUTER\s+)?|RIGHT\s+(?:OUTER\s+)?)?JOIN)\s+/i';
    $parts = preg_split($joinSplitRegex, trim($fromClause), -1, PREG_SPLIT_DELIM_CAPTURE);
    if (empty($parts[0])) return null;

    $parseTableRef = static function (string $ref): ?array {
        if (!preg_match('/^[`"\']?([\w\-]+)[`"\']?(?:\s+(?:AS\s+)?[`"\']?([\w\-]+)[`"\']?)?$/i', trim($ref), $tm)) {
            return null;
        }
        $tbl = $tm[1];
        $alias = !empty($tm[2]) ? $tm[2] : $tbl;
        return [$tbl, $alias];
    };

    $baseRef = $parseTableRef($parts[0]);
    if ($baseRef === null) return null;
    [$baseTable, $baseAlias] = $baseRef;
    if (!table_exists($baseTable)) return [];

    $baseData = get_table($baseTable);
    $hasJoins = count($parts) > 1;
    if (!$hasJoins && $baseAlias === $baseTable) {
        return $baseData['data'];
    }

    // Build qualified rows so table/alias prefixes work in WHERE, SELECT, ORDER BY
    $rows = [];
    foreach ($baseData['data'] as $r) {
        $rows[] = di_sql_prefix_row($r, $baseAlias, $baseData['fields'], false);
    }

    for ($idx = 1; $idx + 1 < count($parts); $idx += 2) {
        $joinTypeRaw = strtoupper(preg_replace('/\s+/', ' ', trim($parts[$idx])));
        $joinSpec = trim($parts[$idx + 1]);
        if (!preg_match('/^(.+?)\s+ON\s+(.+)$/is', $joinSpec, $jm)) {
            continue;
        }
        $jRef = $parseTableRef($jm[1]);
        if ($jRef === null) continue;
        [$jTable, $jAlias] = $jRef;
        $onTokens = di_sql_tokenize_where($jm[2]);

        $jData = table_exists($jTable) ? get_table($jTable) : ['fields' => ['id'], 'data' => []];
        $jRows = [];
        foreach ($jData['data'] as $jr) {
            $jRows[] = di_sql_prefix_row($jr, $jAlias, $jData['fields'], false);
        }

        $isLeft  = str_starts_with($joinTypeRaw, 'LEFT');
        $isRight = str_starts_with($joinTypeRaw, 'RIGHT');
        $merged  = [];

        if ($isRight) {
            $leftFields = !empty($rows) ? array_values(array_filter(array_keys($rows[0]), static fn($k) => !str_contains($k, '.'))) : $baseData['fields'];
            $nullLeft = di_sql_prefix_row([], $baseAlias, $leftFields, true);
            foreach ($jRows as $jr) {
                $matched = false;
                foreach ($rows as $lr) {
                    $cand = array_merge($jr, $lr);
                    if (di_sql_eval_where($cand, $onTokens)) {
                        $merged[] = $cand;
                        $matched = true;
                    }
                }
                if (!$matched) {
                    $merged[] = array_merge($nullLeft, $jr);
                }
            }
        } else {
            $nullRight = di_sql_prefix_row([], $jAlias, $jData['fields'], true);
            foreach ($rows as $lr) {
                $matched = false;
                foreach ($jRows as $jr) {
                    // Keep existing bare keys from left table if both tables share column names like `id`
                    $cand = $lr;
                    foreach ($jr as $jk => $jv) {
                        if (str_contains($jk, '.') || !array_key_exists($jk, $cand)) {
                            $cand[$jk] = $jv;
                        }
                    }
                    if (di_sql_eval_where($cand, $onTokens)) {
                        $merged[] = $cand;
                        $matched = true;
                    }
                }
                if (!$matched && $isLeft) {
                    $cand = $lr;
                    foreach ($nullRight as $jk => $jv) {
                        if (str_contains($jk, '.') || !array_key_exists($jk, $cand)) {
                            $cand[$jk] = $jv;
                        }
                    }
                    $merged[] = $cand;
                }
            }
        }
        $rows = $merged;
    }

    return $rows;
}

function di_sql_compute_aggregate(string $fn, string $arg, bool $distinctArg, array $groupRows) {
    $fn = strtoupper($fn);
    if ($fn === 'COUNT' && trim($arg) === '*') {
        return count($groupRows);
    }
    $vals = [];
    foreach ($groupRows as $r) {
        $v = di_sql_lookup_row_col($r, $arg);
        if ($v !== null && $v !== '') {
            $vals[] = $v;
        }
    }
    if ($distinctArg) {
        $vals = array_values(array_unique(array_map('strval', $vals)));
    }
    if ($fn === 'COUNT') {
        return count($vals);
    }
    if (empty($vals)) {
        return '';
    }
    $nums = array_map('floatval', $vals);
    return match ($fn) {
        'SUM' => (floor(array_sum($nums)) == array_sum($nums)) ? (int) array_sum($nums) : array_sum($nums),
        'AVG' => array_sum($nums) / count($nums),
        'MIN' => array_reduce($vals, static fn($carry, $item) => $carry === null ? $item : ((is_numeric($carry) && is_numeric($item) ? ((float) $item < (float) $carry) : ((string) $item < (string) $carry)) ? $item : $carry), null),
        'MAX' => array_reduce($vals, static fn($carry, $item) => $carry === null ? $item : ((is_numeric($carry) && is_numeric($item) ? ((float) $item > (float) $carry) : ((string) $item > (string) $carry)) ? $item : $carry), null),
        default => '',
    };
}

function di_query_select(string $sql): array {
    $clauses = di_sql_split_select_clauses($sql);
    if ($clauses === null || $clauses['from'] === null || $clauses['from'] === '') {
        return [];
    }

    $rawRows = di_sql_load_from_and_joins($clauses['from']);
    if ($rawRows === null || empty($rawRows)) {
        // Still return aggregate row for SELECT COUNT(*) FROM empty_table (native SQL returns 1 row with 0!)
        if ($rawRows !== null && $clauses['group'] === null && preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $clauses['select'])) {
            $rawRows = [];
        } else {
            return [];
        }
    }

    // 1. WHERE filter
    if ($clauses['where'] !== null && $clauses['where'] !== '') {
        $whereTokens = di_sql_tokenize_where($clauses['where']);
        $filtered = [];
        foreach ($rawRows as $r) {
            if (di_sql_eval_where($r, $whereTokens)) {
                $filtered[] = $r;
            }
        }
        $rawRows = $filtered;
    }

    // Parse SELECT column specs for projections and/or aggregates
    $selectExpr = $clauses['select'];
    $colSpecs = di_sql_split_csv_outside_quotes($selectExpr);
    $hasAggregate = false;
    $parsedCols = [];
    foreach ($colSpecs as $spec) {
        $specTrim = trim($spec);
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\s*\(\s*(DISTINCT\s+)?(\*|[\w\-\.`"\']+)\s*\)(?:\s+(?:AS\s+)?[`"\']?([\w\-]+)[`"\']?)?$/i', $specTrim, $am)) {
            $hasAggregate = true;
            $rawCall = $am[1] . '(' . ($am[2] ? 'DISTINCT ' : '') . $am[3] . ')';
            $alias = !empty($am[4]) ? $am[4] : substr($specTrim, 0, strpos($specTrim . ' ', ')') + 1);
            $parsedCols[] = [
                'kind'     => 'agg',
                'fn'       => strtoupper($am[1]),
                'distinct' => !empty($am[2]),
                'arg'      => $am[3],
                'alias'    => $alias,
                'raw_expr' => strtoupper(preg_replace('/\s+/', '', $rawCall)),
            ];
        } elseif ($specTrim === '*' || preg_match('/^[`"\']?([\w\-]+)[`"\']?\.\*$/', $specTrim)) {
            $parsedCols[] = ['kind' => 'star', 'prefix' => str_contains($specTrim, '.') ? trim(explode('.', $specTrim)[0], "`'\"") : null];
        } elseif (preg_match('/^([`"\']?[\w\-]+[`"\']?(?:\.[`"\']?[\w\-]+[`"\']?)?)(?:\s+(?:AS\s+)?[`"\']?([\w\-]+)[`"\']?)?$/i', $specTrim, $pm)) {
            $src = $pm[1];
            $defaultAlias = str_contains($src, '.') ? explode('.', $src, 2)[1] : $src;
            $defaultAlias = di_sql_strip_quotes($defaultAlias);
            $alias = !empty($pm[2]) ? $pm[2] : $defaultAlias;
            $parsedCols[] = ['kind' => 'col', 'src' => $src, 'alias' => $alias];
        }
    }

    // 2. GROUP BY & AGGREGATION
    $rows = [];
    if ($hasAggregate || ($clauses['group'] !== null && $clauses['group'] !== '')) {
        $groups = [];
        if ($clauses['group'] !== null && $clauses['group'] !== '') {
            $groupCols = di_sql_split_csv_outside_quotes($clauses['group']);
            foreach ($rawRows as $r) {
                $gKeyParts = [];
                foreach ($groupCols as $gc) {
                    $gKeyParts[] = (string) (di_sql_lookup_row_col($r, trim($gc)) ?? '');
                }
                $gk = implode("\x1F", $gKeyParts);
                $groups[$gk][] = $r;
            }
        } else {
            // Ungrouped aggregate across entire table (returns 1 row even if 0 rows matched, matching SQL!)
            $groups['__all__'] = $rawRows;
        }

        foreach ($groups as $gRows) {
            $sample = $gRows[0] ?? [];
            $built = [];
            $havingCtx = $sample;
            foreach ($parsedCols as $pc) {
                if ($pc['kind'] === 'agg') {
                    $val = di_sql_compute_aggregate($pc['fn'], $pc['arg'], $pc['distinct'], $gRows);
                    $built[$pc['alias']] = $val;
                    $havingCtx[$pc['alias']] = $val;
                    $havingCtx[$pc['fn'] . '(' . $pc['arg'] . ')'] = $val;
                } elseif ($pc['kind'] === 'col') {
                    $v = di_sql_lookup_row_col($sample, $pc['src']);
                    $built[$pc['alias']] = $v === null ? '' : $v;
                    $havingCtx[$pc['alias']] = $built[$pc['alias']];
                } elseif ($pc['kind'] === 'star') {
                    foreach ($sample as $sk => $sv) {
                        if (!str_contains((string) $sk, '.')) {
                            $built[$sk] = $sv === null ? '' : $sv;
                        }
                    }
                }
            }
            if ($clauses['having'] !== null && $clauses['having'] !== '') {
                $havTokens = di_sql_tokenize_where($clauses['having']);
                if (!di_sql_eval_where($havingCtx, $havTokens)) {
                    continue;
                }
            }
            $built['__ctx'] = $havingCtx;
            $rows[] = $built;
        }
    } else {
        $rows = $rawRows;
    }

    // 3. ORDER BY (runs before stripping unprojected columns so ORDER BY non-selected col works like SQL!)
    if ($clauses['order'] !== null && $clauses['order'] !== '') {
        $orderParts = di_sql_split_csv_outside_quotes($clauses['order']);
        $sorters = [];
        foreach ($orderParts as $op) {
            if (preg_match('/^(.+?)(?:\s+(ASC|DESC))?$/i', trim($op), $om)) {
                $colRef = trim($om[1]);
                $dir = (!empty($om[2]) && strtoupper($om[2]) === 'DESC') ? -1 : 1;
                $sorters[] = [$colRef, $dir];
            }
        }
        if (!empty($sorters)) {
            usort($rows, static function (array $a, array $b) use ($sorters): int {
                foreach ($sorters as [$colRef, $dir]) {
                    $srcA = $a['__ctx'] ?? $a;
                    $srcB = $b['__ctx'] ?? $b;
                    $va = di_sql_lookup_row_col($srcA, $colRef) ?? '';
                    $vb = di_sql_lookup_row_col($srcB, $colRef) ?? '';
                    $cmp = (is_numeric($va) && is_numeric($vb))
                        ? ((float) $va <=> (float) $vb)
                        : strnatcasecmp((string) $va, (string) $vb);
                    if ($cmp !== 0) return $cmp * $dir;
                }
                return 0;
            });
        }
    }

    // 4. Final Column Projection
    $projected = [];
    if ($hasAggregate || ($clauses['group'] !== null && $clauses['group'] !== '')) {
        foreach ($rows as $r) {
            unset($r['__ctx']);
            $projected[] = $r;
        }
    } else {
        foreach ($rows as $r) {
            $item = [];
            foreach ($parsedCols as $pc) {
                if ($pc['kind'] === 'star') {
                    if ($pc['prefix'] !== null) {
                        $pfx = $pc['prefix'] . '.';
                        foreach ($r as $rk => $rv) {
                            if (str_starts_with((string) $rk, $pfx)) {
                                $bare = substr((string) $rk, strlen($pfx));
                                $item[$bare] = $rv === null ? '' : $rv;
                            }
                        }
                    } else {
                        foreach ($r as $rk => $rv) {
                            if (!str_contains((string) $rk, '.')) {
                                $item[$rk] = $rv === null ? '' : $rv;
                            }
                        }
                    }
                } elseif ($pc['kind'] === 'col') {
                    $val = di_sql_lookup_row_col($r, $pc['src']);
                    $item[$pc['alias']] = $val === null ? '' : $val;
                }
            }
            $projected[] = $item;
        }
    }
    $rows = $projected;

    // 5. DISTINCT
    if (!empty($clauses['distinct'])) {
        $seen = [];
        $dedup = [];
        foreach ($rows as $r) {
            $k = serialize($r);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $dedup[] = $r;
            }
        }
        $rows = $dedup;
    }

    // 6. LIMIT / OFFSET
    if ($clauses['limit'] !== null && $clauses['limit'] !== '') {
        $limitExpr = $clauses['limit'];
        $offset = 0;
        $limit = null;
        if (preg_match('/^(\d+)\s+OFFSET\s+(\d+)$/i', $limitExpr, $lm)) {
            $limit  = (int) $lm[1];
            $offset = (int) $lm[2];
        } elseif (preg_match('/^(\d+)\s*,\s*(\d+)$/', $limitExpr, $lm)) {
            $offset = (int) $lm[1];
            $limit  = (int) $lm[2];
        } elseif (preg_match('/^(\d+)$/', $limitExpr, $lm)) {
            $limit  = (int) $lm[1];
        }
        if ($limit !== null) {
            $rows = array_slice($rows, $offset, $limit);
        }
    }

    return array_values($rows);
}

/* ==========================================================================
 * 5. INSERT, REPLACE, UPDATE, DELETE & DDL STATEMENTS
 * ========================================================================== */

function di_query_insert(string $sql, bool $isReplace = false) {
    $pattern = '/^(?:INSERT|REPLACE)\s+INTO\s+[`"\']?([\w\-]+)[`"\']?\s*(?:\((.+?)\))?\s*VALUES?\s*(.+)$/is';
    if (!preg_match($pattern, trim($sql), $m)) return false;

    $table = trim($m[1]);
    $colsRaw = isset($m[2]) && trim($m[2]) !== '' ? di_sql_split_csv_outside_quotes($m[2]) : [];
    $cols = array_map('di_sql_strip_quotes', $colsRaw);
    $valuesPart = trim($m[3]);

    if (empty($cols)) {
        $existing = get_table($table);
        $cols = array_values(array_filter($existing['fields'], static fn($f) => strcasecmp($f, 'id') !== 0));
    }

    $tuples = [];
    $len = strlen($valuesPart);
    $inQuote = null;
    $depth = 0;
    $buf = '';
    for ($i = 0; $i < $len; $i++) {
        $ch = $valuesPart[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) $buf .= $valuesPart[++$i];
            elseif ($ch === $inQuote) {
                if ($i + 1 < $len && $valuesPart[$i + 1] === $inQuote) $buf .= $valuesPart[++$i];
                else $inQuote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inQuote = $ch;
            if ($depth > 0) $buf .= $ch;
        } elseif ($ch === '(') {
            if ($depth > 0) $buf .= $ch;
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                $tuples[] = $buf;
                $buf = '';
            } elseif ($depth > 0) {
                $buf .= $ch;
            }
        } elseif ($depth > 0) {
            $buf .= $ch;
        }
    }

    $rows = [];
    foreach ($tuples as $tuple) {
        $vals = array_map('di_sql_strip_quotes', di_sql_split_csv_outside_quotes($tuple));
        $record = [];
        foreach ($cols as $idx => $colName) {
            $v = $vals[$idx] ?? '';
            $record[$colName] = (strtoupper($v) === 'NULL') ? '' : $v;
        }
        $rows[] = $record;
    }

    $ids = multi_update_table($table, $rows, $isReplace ? 'update' : 'insert');
    if (empty($ids)) return false;
    return (string) end($ids);
}

function di_query_update(string $sql): int {
    // Split SET ... WHERE ... outside quotes
    if (!preg_match('/^UPDATE\s+[`"\']?([\w\-]+)[`"\']?\s+SET\s+(.+)$/is', trim($sql), $m)) {
        return 0;
    }
    $table = trim($m[1]);
    if (!table_exists($table)) return 0;

    $tail = $m[2];
    $setPart = $tail;
    $whereExpr = null;
    $len = strlen($tail);
    $inQuote = null;
    $depth = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = $tail[$i];
        if ($inQuote !== null) {
            if ($ch === '\\' && $i + 1 < $len) $i++;
            elseif ($ch === $inQuote) {
                if ($i + 1 < $len && $tail[$i + 1] === $inQuote) $i++;
                else $inQuote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $inQuote = $ch; continue; }
        if ($ch === '(') { $depth++; continue; }
        if ($ch === ')') { $depth = max(0, $depth - 1); continue; }
        if ($depth === 0 && ($i === 0 || ctype_space($tail[$i - 1])) && preg_match('/^WHERE(?=\s)/i', substr($tail, $i))) {
            $setPart = trim(substr($tail, 0, $i));
            $whereExpr = trim(substr($tail, $i + 5));
            break;
        }
    }

    $assignments = [];
    foreach (di_sql_split_csv_outside_quotes($setPart) as $assign) {
        $eqPos = strpos($assign, '=');
        if ($eqPos !== false) {
            $col = di_sql_strip_quotes(substr($assign, 0, $eqPos));
            $rawRhs = trim(substr($assign, $eqPos + 1));
            // Support arithmetic updates: SET hits = hits + 1 or SET score = score - 5
            if (preg_match('/^[`"\']?([\w\-]+)[`"\']?\s*([\+\-\*])\s*(-?\d+(?:\.\d+)?)$/', $rawRhs, $am)) {
                $assignments[$col] = ['expr' => true, 'src' => $am[1], 'op' => $am[2], 'num' => (float) $am[3]];
            } else {
                $val = di_sql_strip_quotes($rawRhs);
                $assignments[$col] = ['expr' => false, 'val' => (strtoupper($val) === 'NULL') ? '' : $val];
            }
        }
    }
    if (empty($assignments)) return 0;

    $tableData = get_table($table);
    $whereTokens = ($whereExpr !== null && $whereExpr !== '') ? di_sql_tokenize_where($whereExpr) : [];
    $toUpdate = [];
    foreach ($tableData['data'] as $row) {
        if (empty($whereTokens) || di_sql_eval_where($row, $whereTokens)) {
            $upd = ['id' => $row['id']];
            foreach ($assignments as $col => $spec) {
                if ($spec['expr']) {
                    $baseNum = (float) ($row[$spec['src']] ?? 0);
                    $resNum = match ($spec['op']) {
                        '+' => $baseNum + $spec['num'],
                        '-' => $baseNum - $spec['num'],
                        '*' => $baseNum * $spec['num'],
                        default => $baseNum,
                    };
                    $upd[$col] = (floor($resNum) == $resNum) ? (string) ((int) $resNum) : (string) $resNum;
                } else {
                    $upd[$col] = $spec['val'];
                }
            }
            $toUpdate[] = $upd;
        }
    }
    return count(multi_update_table($table, $toUpdate, 'update'));
}

function di_query_delete(string $sql): int {
    $pattern = '/^DELETE\s+FROM\s+[`"\']?([\w\-]+)[`"\']?(?:\s+WHERE\s+(.+))?$/is';
    if (!preg_match($pattern, trim($sql), $m)) return 0;
    $table = trim($m[1]);
    $whereExpr = isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : null;
    if (!table_exists($table)) return 0;

    $tableData = get_table($table);
    $whereTokens = $whereExpr !== null ? di_sql_tokenize_where($whereExpr) : [];
    $toDelete = [];
    foreach ($tableData['data'] as $row) {
        if (empty($whereTokens) || di_sql_eval_where($row, $whereTokens)) {
            $toDelete[] = ['id' => $row['id']];
        }
    }
    return count(multi_update_table($table, $toDelete, 'delete'));
}

function di_query_create(string $sql): bool {
    $pattern = '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`"\']?([\w\-]+)[`"\']?\s*\((.+)\)/is';
    if (!preg_match($pattern, trim($sql), $m)) return false;
    $ifNotExists = !empty($m[1]);
    $table = trim($m[2]);
    $fields = [];
    foreach (di_sql_split_csv_outside_quotes($m[3]) as $def) {
        $def = trim($def);
        if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN)\b/i', $def)) continue;
        if (preg_match('/^[`"\']?([\w\-]+)[`"\']?/', $def, $fm)) {
            $col = $fm[1];
            if (strcasecmp($col, 'id') !== 0) $fields[] = $col;
        }
    }
    $created = create_table($table, $fields);
    return $ifNotExists ? true : $created;
}

function di_query_alter(string $sql): bool {
    if (preg_match('/^ALTER\s+TABLE\s+[`"\']?([\w\-]+)[`"\']?\s+ADD\s+(?:COLUMN\s+)?[`"\']?([\w\-]+)[`"\']?/i', trim($sql), $m)) {
        ensure_fields($m[1], [$m[2]]);
        return true;
    }
    return false;
}

function di_query_truncate(string $sql): bool {
    if (!preg_match('/^TRUNCATE\s+(?:TABLE\s+)?[`"\']?([\w\-]+)[`"\']?$/i', trim($sql), $m)) {
        return false;
    }
    $table = trim($m[1]);
    if (!table_exists($table)) return false;
    $fields = get_table($table)['fields'];
    drop_table($table);
    create_table($table, $fields);
    return true;
}

function di_query_drop(string $sql): bool {
    if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"\']?([\w\-]+)[`"\']?$/i', trim($sql), $m)) {
        return drop_table($m[1]);
    }
    return false;
}

function di_query_describe(string $sql) {
    if (preg_match('/^SHOW\s+TABLES$/i', trim($sql))) {
        return function_exists('list_tables')
            ? array_map(static fn($t) => ['table' => $t], list_tables())
            : [];
    }
    if (!preg_match('/^(?:DESCRIBE|DESC|SHOW\s+COLUMNS\s+FROM)\s+[`"\']?([\w\-]+)[`"\']?$/i', trim($sql), $m)) {
        return false;
    }
    $table = trim($m[1]);
    if (!table_exists($table)) return false;
    $t = get_table($table);
    $ret = ['fields' => [], 'type' => [], 'null' => [], 'key' => [], 'default' => [], 'extra' => []];
    foreach ($t['fields'] as $k) {
        $ret['fields'][]  = $k;
        $ret['type'][]    = ($k === 'id') ? 'int' : 'text';
        $ret['null'][]    = ($k === 'id') ? 'NO' : 'YES';
        $ret['key'][]     = ($k === 'id') ? 'PRI' : '';
        $ret['default'][] = 'NULL';
        $ret['extra'][]   = ($k === 'id') ? 'auto_increment' : '';
    }
    return $ret;
}
