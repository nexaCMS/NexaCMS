<?php
/**
 * NexaCMS CSV table storage (includes/MyCSV.class.php)
 *
 * Original implementation written for NexaCMS and released under the
 * GNU General Public License v3.0 (see the LICENSE file at the project
 * root). It replaces the legacy third-party CSV class that earlier
 * versions bundled, keeping the small table API the storage layer
 * relies on while reading and writing the same on-disk format:
 * one header row followed by one record per line, standard CSV
 * quoting, first column "id".
 *
 * A table is loaded fully into memory on construction; mutations are
 * held in memory until write() persists them. close() simply releases
 * the in-memory copy and never writes implicitly.
 */
declare(strict_types=1);

class MyCSV
{
    /** @var string Path of the backing .csv file. */
    private string $file;

    /** @var string[] Column names; the first column is always "id". */
    private array $columns = ['id'];

    /** @var array<int, array<string, string>> Rows keyed by column name. */
    private array $rows = [];

    /** @var int Cursor for reset()/each() iteration. */
    private int $cursor = 0;

    /** @var int Id handed out by the most recent insert(). */
    private int $lastInsertId = 0;

    /**
     * Open a table. Accepts a base path (".csv" is appended) or a full
     * path ending in ".csv". A missing file starts as an empty table.
     */
    public function __construct(string $path)
    {
        $this->file = str_ends_with(strtolower($path), '.csv') ? $path : $path . '.csv';
        if (is_file($this->file)) {
            $this->load();
        }
    }

    private function load(): void
    {
        $handle = fopen($this->file, 'rb');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (is_array($header) && $header !== [] && $header !== [null]) {
            $header = array_map(static fn ($h) => trim((string) $h), $header);
            if (!in_array('id', $header, true)) {
                array_unshift($header, 'id');
            }
            $this->columns = array_values($header);
        }
        while (($record = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($record === [null] || $record === []) {
                continue; // tolerate blank lines
            }
            $row = [];
            foreach ($this->columns as $i => $column) {
                $row[$column] = isset($record[$i]) ? (string) $record[$i] : '';
            }
            // Ids are integers in memory (the storage layer relies on it);
            // every other cell stays a string, exactly as stored.
            if (isset($row['id']) && is_numeric($row['id'])) {
                $row['id'] = (int) $row['id'];
            }
            $this->rows[] = $row;
        }
        fclose($handle);
    }

    /** @return string[] Column names in file order ("id" first). */
    public function fields(): array
    {
        return $this->columns;
    }

    /** Append a column (existing rows receive empty values). */
    public function add_field(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || in_array($name, $this->columns, true)) {
            return false;
        }
        $this->columns[] = $name;
        foreach ($this->rows as &$row) {
            $row[$name] = '';
        }
        unset($row);
        return true;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** @return string[] All row ids in current order. */
    public function ids(): array
    {
        return array_map(static fn (array $row) => (string) ($row['id'] ?? ''), $this->rows);
    }

    public function id_exists($id): bool
    {
        return $this->indexOf($id) !== -1;
    }

    private function indexOf($id): int
    {
        $needle = (string) $id;
        foreach ($this->rows as $i => $row) {
            if ((string) ($row['id'] ?? '') === $needle) {
                return $i;
            }
        }
        return -1;
    }

    private function nextId(): int
    {
        $max = 0;
        foreach ($this->rows as $row) {
            $value = (int) ($row['id'] ?? 0);
            if ($value > $max) {
                $max = $value;
            }
        }
        return $max + 1;
    }

    /** @param mixed $value Normalise a cell to its stored string form. */
    private function cell($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $json = json_encode($value);
        return $json === false ? '' : $json;
    }

    /**
     * Insert a row. A non-empty "id" in $row is honoured; otherwise the
     * next free id is assigned. Unknown keys become new columns.
     */
    public function insert(array $row): int
    {
        foreach (array_keys($row) as $key) {
            $this->add_field((string) $key);
        }
        $id = isset($row['id']) && (string) $row['id'] !== '' ? (int) $row['id'] : $this->nextId();
        $stored = [];
        foreach ($this->columns as $column) {
            $stored[$column] = $column === 'id' ? $id : $this->cell($row[$column] ?? '');
        }
        $this->rows[] = $stored;
        $this->lastInsertId = $id;
        return $id;
    }

    public function insert_id(): int
    {
        return $this->lastInsertId;
    }

    /** Merge $row into the stored row identified by $whereId (or $row['id']). */
    public function update(array $row, $whereId = null): bool
    {
        $target = $whereId ?? ($row['id'] ?? null);
        if ($target === null) {
            return false;
        }
        $index = $this->indexOf($target);
        if ($index === -1) {
            return false;
        }
        foreach ($row as $key => $value) {
            $key = (string) $key;
            if ($key === 'id') {
                continue;
            }
            $this->add_field($key);
            $this->rows[$index][$key] = $this->cell($value);
        }
        $this->lastInsertId = (int) $this->rows[$index]['id'];
        return true;
    }

    public function delete($id): bool
    {
        $index = $this->indexOf($id);
        if ($index === -1) {
            return false;
        }
        array_splice($this->rows, $index, 1);
        return true;
    }

    /**
     * Sort rows in memory by a "<column> [ASC|DESC]" spec, e.g.
     * "post_date DESC". Numeric values compare numerically.
     */
    public function sort(string $spec): void
    {
        $parts = preg_split('/\s+/', trim($spec)) ?: [];
        $column = (string) ($parts[0] ?? 'id');
        if (!in_array($column, $this->columns, true)) {
            return;
        }
        $descending = isset($parts[1]) && strtoupper((string) $parts[1]) === 'DESC';
        usort($this->rows, static function (array $a, array $b) use ($column, $descending): int {
            $x = (string) ($a[$column] ?? '');
            $y = (string) ($b[$column] ?? '');
            $cmp = (is_numeric($x) && is_numeric($y))
                ? ((float) $x <=> (float) $y)
                : strnatcasecmp($x, $y);
            return $descending ? -$cmp : $cmp;
        });
        $this->cursor = 0;
    }

    public function reset(): void
    {
        $this->cursor = 0;
    }

    /** Next row as an associative array, or false when exhausted. */
    public function each(): array|false
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->cursor++];
    }

    /** Persist header + rows to the backing file. */
    public function write(): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = fopen($this->file, 'wb');
        if ($handle === false) {
            return false;
        }
        flock($handle, LOCK_EX);
        fputcsv($handle, $this->columns, ',', '"', '\\');
        foreach ($this->rows as $row) {
            $line = [];
            foreach ($this->columns as $column) {
                $line[] = (string) ($row[$column] ?? '');
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return true;
    }

    /** Release the in-memory table. Never writes implicitly. */
    public function close(): void
    {
        $this->rows = [];
        $this->cursor = 0;
    }
}
