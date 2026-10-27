<?php

declare(strict_types=1);

use App\Http\Controllers\Web\PositionController;
use App\Models\Position;

/**
 * The position status page aggregates in SQL. The live server runs MySQL,
 * whose CAST has no VARCHAR type and whose `||` is logical OR, so the
 * expressions must be built per driver (local dev is PostgreSQL/SQLite).
 */
function positionStatusRowsSql(string $connection): string
{
    $controller = app(PositionController::class);
    $method = new ReflectionMethod($controller, 'positionStatusRows');

    return $method->invoke($controller, Position::on($connection)->newQuery(), null)->toSql();
}

it('builds the status rows with MySQL syntax on a mysql connection', function (): void {
    $sql = positionStatusRowsSql('mysql');

    expect($sql)->not->toContain('VARCHAR')
        ->and($sql)->not->toContain('||')
        ->and($sql)->toContain('CONCAT(')
        ->and($sql)->toContain('CAST(positions.organization_id AS CHAR(64))');
});

it('keeps the PostgreSQL status rows on || concatenation and VARCHAR casts', function (): void {
    $sql = positionStatusRowsSql('pgsql');

    expect($sql)->toContain('CAST(positions.organization_id AS VARCHAR(64))')
        ->and($sql)->toContain(" || '|' || ")
        ->and($sql)->not->toContain('CONCAT(');
});

it('casts the breakdown key per driver', function (): void {
    $controller = app(PositionController::class);
    $method = new ReflectionMethod($controller, 'idText');

    expect($method->invoke($controller, Position::on('mysql')->getConnection(), 'ps.organization_id'))->toBe('CAST(ps.organization_id AS CHAR(64))')
        ->and($method->invoke($controller, Position::on('pgsql')->getConnection(), 'ps.organization_id'))->toBe('CAST(ps.organization_id AS VARCHAR(64))')
        ->and($method->invoke($controller, Position::on('sqlite')->getConnection(), 'ps.organization_id'))->toBe('CAST(ps.organization_id AS TEXT)');
});
