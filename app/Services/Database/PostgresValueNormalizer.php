<?php

namespace App\Services\Database;

use Illuminate\Database\Connection;

/**
 * Converts loosely typed SQLite row values into values a PostgreSQL table
 * accepts unchanged, or fails loudly.
 *
 * SQLite stores almost anything in any column; PostgreSQL rejects over-length
 * strings, malformed dates, out-of-range integers and invalid JSON, and would
 * silently truncate a non-midnight time stored in a `date` column. Every such
 * case raises {@see InvalidSourceValue} instead of being coerced.
 */
class PostgresValueNormalizer
{
    private const INTEGER_RANGES = [
        'int2' => [-32768, 32767],
        'int4' => [-2147483648, 2147483647],
        'int8' => [PHP_INT_MIN, PHP_INT_MAX],
    ];

    /**
     * @param  array<string, array{type_name: string, type: string, nullable: bool}>  $columns  target columns keyed by name
     */
    public function __construct(private readonly array $columns) {}

    /**
     * Build a normalizer from a PostgreSQL table's column metadata.
     */
    public static function forTable(Connection $connection, string $table): self
    {
        $columns = [];

        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            $columns[$column['name']] = [
                'type_name' => $column['type_name'],
                'type' => $column['type'],
                'nullable' => $column['nullable'],
            ];
        }

        return new self($columns);
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Normalize every value of a source row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws InvalidSourceValue
     */
    public function normalize(array $row): array
    {
        $normalized = [];

        foreach ($row as $column => $value) {
            $normalized[$column] = $this->value($column, $value);
        }

        return $normalized;
    }

    /**
     * @throws InvalidSourceValue
     */
    private function value(string $column, mixed $value): mixed
    {
        $definition = $this->columns[$column]
            ?? throw new InvalidSourceValue($column, 'column does not exist in the target table');

        if ($value === null) {
            if (! $definition['nullable']) {
                throw new InvalidSourceValue($column, 'null in a NOT NULL column');
            }

            return null;
        }

        return match ($definition['type_name']) {
            'int2', 'int4', 'int8' => $this->integer($column, $value, $definition['type_name']),
            'bool' => $this->boolean($column, $value),
            'numeric' => $this->numeric($column, $value, $definition['type']),
            'date' => $this->date($column, $value),
            'timestamp' => $this->timestamp($column, $value),
            'json', 'jsonb' => $this->json($column, $value),
            'varchar', 'bpchar' => $this->varchar($column, $value, $definition['type']),
            default => $this->text($column, $value),
        };
    }

    private function integer(string $column, mixed $value, string $typeName): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if (is_bool($value) || $integer === false) {
            throw new InvalidSourceValue($column, "not an integer for {$typeName}");
        }

        [$min, $max] = self::INTEGER_RANGES[$typeName];

        if ($integer < $min || $integer > $max) {
            throw new InvalidSourceValue($column, "integer out of range for {$typeName}");
        }

        return $integer;
    }

    private function boolean(string $column, mixed $value): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => throw new InvalidSourceValue($column, 'boolean must be 0 or 1'),
        };
    }

    private function numeric(string $column, mixed $value, string $type): string
    {
        if (is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidSourceValue($column, 'not a finite number');
        }

        if (! preg_match('/^numeric\((\d+),(\d+)\)$/', $type, $matches)) {
            return (string) $value;
        }

        [$precision, $scale] = [(int) $matches[1], (int) $matches[2]];
        $formatted = sprintf("%.{$scale}F", (float) $value);
        $integerDigits = strlen(ltrim(explode('.', ltrim($formatted, '-'))[0], '0'));

        if ($integerDigits > $precision - $scale) {
            throw new InvalidSourceValue($column, "number exceeds {$type}");
        }

        return $formatted;
    }

    private function date(string $column, mixed $value): string
    {
        $string = $this->text($column, $value);

        // Eloquent date casts may have stored a midnight datetime; any other
        // time would be silently dropped by PostgreSQL, so it is rejected.
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})( 00:00:00)?$/', $string, $matches)
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidSourceValue($column, 'not a calendar date (YYYY-MM-DD)');
        }

        return substr($string, 0, 10);
    }

    private function timestamp(string $column, mixed $value): string
    {
        $string = $this->text($column, $value);

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?)?$/', $string, $matches)
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) ($matches[4] ?? 0) > 23 || (int) ($matches[5] ?? 0) > 59 || (int) ($matches[6] ?? 0) > 59) {
            throw new InvalidSourceValue($column, 'not a timestamp without time zone (YYYY-MM-DD HH:MM:SS)');
        }

        return $string;
    }

    private function json(string $column, mixed $value): string
    {
        $string = $this->text($column, $value);

        if (! json_validate($string)) {
            throw new InvalidSourceValue($column, 'invalid JSON');
        }

        return $string;
    }

    private function varchar(string $column, mixed $value, string $type): string
    {
        $string = $this->text($column, $value);

        if (preg_match('/\((\d+)\)$/', $type, $matches) && mb_strlen($string, 'UTF-8') > (int) $matches[1]) {
            throw new InvalidSourceValue($column, "string longer than {$type}");
        }

        return $string;
    }

    private function text(string $column, mixed $value): string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || is_resource($value)) {
            throw new InvalidSourceValue($column, 'not a scalar text value');
        }

        $string = (string) $value;

        if (! mb_check_encoding($string, 'UTF-8')) {
            throw new InvalidSourceValue($column, 'text is not valid UTF-8');
        }

        if (str_contains($string, "\0")) {
            throw new InvalidSourceValue($column, 'text contains a NUL byte');
        }

        return $string;
    }
}
