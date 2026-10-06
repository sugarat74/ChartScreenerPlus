<?php

namespace Tests\Unit;

use App\Services\Database\InvalidSourceValue;
use App\Services\Database\PostgresValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dry-run validator of `db:copy-sqlite-to-pgsql`, exercised with the
 * column metadata PostgreSQL reports for the real migrations. Runs without a
 * PostgreSQL server.
 */
class PostgresValueNormalizerTest extends TestCase
{
    private function normalizer(): PostgresValueNormalizer
    {
        return new PostgresValueNormalizer([
            'id' => ['type_name' => 'int8', 'type' => 'bigint', 'nullable' => false],
            'name' => ['type_name' => 'varchar', 'type' => 'character varying(255)', 'nullable' => false],
            'token' => ['type_name' => 'varchar', 'type' => 'character varying(64)', 'nullable' => true],
            'message' => ['type_name' => 'text', 'type' => 'text', 'nullable' => true],
            'active' => ['type_name' => 'bool', 'type' => 'boolean', 'nullable' => false],
            'attempts' => ['type_name' => 'int2', 'type' => 'smallint', 'nullable' => false],
            'total' => ['type_name' => 'int4', 'type' => 'integer', 'nullable' => false],
            'close' => ['type_name' => 'numeric', 'type' => 'numeric(12,4)', 'nullable' => false],
            'rvol' => ['type_name' => 'numeric', 'type' => 'numeric(8,4)', 'nullable' => true],
            'date' => ['type_name' => 'date', 'type' => 'date', 'nullable' => false],
            'created_at' => ['type_name' => 'timestamp', 'type' => 'timestamp(0) without time zone', 'nullable' => true],
            'filters' => ['type_name' => 'json', 'type' => 'json', 'nullable' => false],
        ]);
    }

    public function test_valid_sqlite_values_are_normalized_for_postgres(): void
    {
        $row = $this->normalizer()->normalize([
            'id' => 7,
            'name' => 'Ñandú Corp',
            'token' => null,
            'message' => str_repeat('x', 5000),
            'active' => 1,
            'attempts' => '3',
            'total' => 2147483647,
            'close' => 123.45670000001,
            'rvol' => '1.5',
            'date' => '2026-09-01 00:00:00',
            'created_at' => '2026-09-01 17:34:44',
            'filters' => '{"signal":["ma_cross"],"rsi_min":30}',
        ]);

        $this->assertSame(7, $row['id']);
        $this->assertTrue($row['active']);
        $this->assertSame(3, $row['attempts']);
        $this->assertSame('123.4567', $row['close']);
        $this->assertSame('1.5000', $row['rvol']);
        $this->assertSame('2026-09-01', $row['date']);
        $this->assertSame('2026-09-01 17:34:44', $row['created_at']);
        $this->assertNull($row['token']);
        $this->assertFalse($this->normalizer()->normalize(['active' => '0'])['active']);
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'over-length varchar' => ['name', str_repeat('a', 256), 'longer than character varying(255)'],
            'multibyte over-length varchar' => ['token', str_repeat('é', 65), 'longer than character varying(64)'],
            'null in NOT NULL' => ['name', null, 'NOT NULL'],
            'non-boolean flag' => ['active', 'yes', 'boolean'],
            'integer as text' => ['total', '12abc', 'not an integer'],
            'int4 overflow' => ['total', 2147483648, 'out of range'],
            'int2 overflow' => ['attempts', 40000, 'out of range'],
            'non-numeric decimal' => ['close', 'n/a', 'finite number'],
            'decimal overflow' => ['rvol', 10000.5, 'exceeds numeric(8,4)'],
            'decimal overflow after rounding' => ['rvol', 9999.99999, 'exceeds numeric(8,4)'],
            'impossible date' => ['date', '2026-02-30', 'calendar date'],
            'date with a time' => ['date', '2026-09-01 15:30:00', 'calendar date'],
            'malformed timestamp' => ['created_at', 'yesterday', 'timestamp'],
            'timestamp with offset' => ['created_at', '2026-09-01T10:00:00+02:00', 'timestamp'],
            'invalid json' => ['filters', '{"signal":', 'invalid JSON'],
            'invalid utf-8' => ['message', "caf\xE9", 'UTF-8'],
            'nul byte' => ['message', "a\0b", 'NUL'],
            'unknown column' => ['nickname', 'x', 'does not exist'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_values_postgres_would_reject_or_alter_fail_loudly(string $column, mixed $value, string $reason): void
    {
        try {
            $this->normalizer()->normalize([$column => $value]);
            $this->fail("Expected [{$column}] to be rejected.");
        } catch (InvalidSourceValue $exception) {
            $this->assertSame($column, $exception->column);
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    public function test_the_error_message_never_contains_the_value(): void
    {
        try {
            $this->normalizer()->normalize(['name' => 'secret-'.str_repeat('z', 300)]);
            $this->fail('Expected the over-length name to be rejected.');
        } catch (InvalidSourceValue $exception) {
            $this->assertStringNotContainsString('secret-', $exception->getMessage());
        }
    }
}
