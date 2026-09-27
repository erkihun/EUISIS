<?php

use App\Services\Backup\Infrastructure\InvalidCommandOutput;
use App\Services\Backup\Infrastructure\PgBackRestInfoParser;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../../Fixtures/backup_report.php';

function parseInfo(string $json): array
{
    return (new PgBackRestInfoParser)->parse($json, 'euisis', [1, 2]);
}

it('keeps only allowlisted backup metadata from valid output', function () {
    [$one, $two] = parseInfo(pgbackrestInfo());
    expect($one)->status->toBe('AVAILABLE')->encrypted->toBeTrue()->wal_max->toBe('0000000100000000000000A3')
        ->and($one['backups'][0])->toHaveKeys(['reference', 'type', 'completed_at', 'size'])->not->toHaveKey('annotation')
        ->and($two['backups'])->toHaveCount(1)
        ->and(json_encode([$one, $two]))->not->toContain('TOP_SECRET');
});

it('reports a stanza that is absent or unknown to pgBackRest as STANZA_NOT_FOUND', function ($json) {
    expect(array_column(parseInfo($json), 'status'))->toBe(['STANZA_NOT_FOUND', 'STANZA_NOT_FOUND']);
})->with([
    'empty list' => '[]',
    'other stanza only' => fn () => pgbackrestInfo(stanza: 'other'),
    'missing stanza path' => fn () => pgbackrestInfo(repoCodes: [1 => 1, 2 => 1]),
    'missing stanza data' => fn () => pgbackrestInfo(repoCodes: [1 => 3, 2 => 3]),
]);

it('keeps a reachable repository with no backups available and empty', function () {
    [$one] = parseInfo(pgbackrestInfo(backups: [], repoCodes: [1 => 2, 2 => 2]));
    expect($one)->status->toBe('AVAILABLE')->backups->toBe([]);
});

it('classifies repository errors without returning the tool message', function () {
    $info = json_decode(pgbackrestInfo(repoCodes: [1 => 99, 2 => 99]), true);
    $info[0]['repo'][1]['status']['message'] = "[FileOpenError] raised from remote: unable to open '/srv/secret-path': Permission denied";
    $parsed = parseInfo(json_encode($info));
    expect(array_column($parsed, 'status'))->toBe(['REPOSITORY_UNAVAILABLE', 'PERMISSION_DENIED'])
        ->and(json_encode($parsed))->not->toContain('secret-path');
});

it('marks repositories missing from pgBackRest, unencrypted repositories and errored backups', function () {
    $now = now()->timestamp;
    $parsed = parseInfo(pgbackrestInfo(
        backups: [['label' => '20260927-010000F', 'type' => 'full', 'stop' => $now, 'repo' => 1, 'error' => true]],
        repoCodes: [1 => 0], ciphers: [1 => 'none'],
    ));
    expect($parsed[0])->encrypted->toBeFalse()->errored_backups->toBe(1)->backups->toBe([])
        ->and($parsed[1]['status'])->toBe('REPOSITORY_NOT_CONFIGURED');
});

it('fails closed on invalid, empty or unexpected output', function ($output, $result) {
    expect(fn () => parseInfo($output))->toThrow(fn (InvalidCommandOutput $e) => expect($e->parserResult)->toBe($result));
})->with([
    ['{not json', 'INVALID_JSON'],
    ['', 'EMPTY_OR_OVERSIZED_OUTPUT'],
    ['   ', 'EMPTY_OR_OVERSIZED_OUTPUT'],
    ['{"name":"euisis"}', 'UNEXPECTED_SHAPE'],
    ['[{"name":"euisis"}]', 'UNEXPECTED_SHAPE'],
    ['[{"name":"euisis","status":{"code":0},"repo":[{"key":1,"status":{"code":42,"message":"?"}}]}]', 'UNKNOWN_STATUS_CODE'],
    ['[{"name":"euisis","status":{"code":0},"repo":[],"backup":[{"label":"; rm -rf /","type":"full"}]}]', 'INVALID_BACKUP_ENTRY'],
    ['[{"name":"euisis","status":{"code":0},"repo":[],"backup":"none"}]', 'UNEXPECTED_SHAPE'],
]);
