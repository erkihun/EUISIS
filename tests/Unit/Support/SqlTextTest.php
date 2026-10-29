<?php

declare(strict_types=1);

use App\Support\Database\SqlText;

test('an id column is cast to text with the syntax each driver accepts', function (): void {
    expect(SqlText::idText('positions.organization_id', 'pgsql'))->toBe('CAST(positions.organization_id AS VARCHAR(64))')
        ->and(SqlText::idText('positions.organization_id', 'mysql'))->toBe('CAST(positions.organization_id AS CHAR(64))')
        ->and(SqlText::idText('positions.organization_id', 'mariadb'))->toBe('CAST(positions.organization_id AS CHAR(64))')
        ->and(SqlText::idText('positions.organization_id', 'sqlite'))->toBe('CAST(positions.organization_id AS TEXT)');
});

test('string concatenation uses CONCAT on MySQL and MariaDB and the || operator elsewhere', function (): void {
    $parts = ["COALESCE(a, '')", "'|'", 'b'];

    expect(SqlText::concat($parts, 'mysql'))->toBe("CONCAT(COALESCE(a, ''), '|', b)")
        ->and(SqlText::concat($parts, 'mariadb'))->toBe("CONCAT(COALESCE(a, ''), '|', b)")
        ->and(SqlText::concat($parts, 'pgsql'))->toBe("(COALESCE(a, '') || '|' || b)")
        ->and(SqlText::concat($parts, 'sqlite'))->toBe("(COALESCE(a, '') || '|' || b)");
});

test('an unsafe column name is refused', function (): void {
    SqlText::idText('id); DROP TABLE positions; --', 'pgsql');
})->throws(InvalidArgumentException::class);
