<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\FileSecurityRecord;
use Modules\Court\Application\Services\LegalHoldService;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('secure uploads are privately stored with integrity metadata', function () {
    Storage::fake('court_private');
    config()->set('court.compliance.uploads.scanner', 'none');

    $file = UploadedFile::fake()->createWithContent('evidence.pdf', '%PDF-1.4 test evidence');
    $path = app(SecureUploadService::class)->store($file, 'evidences', 'court_private', [
        'related_type' => 'court_case',
        'related_id' => 42,
        'applicant_id' => 7,
    ]);

    Storage::disk('court_private')->assertExists($path);

    $record = FileSecurityRecord::query()->where('path', $path)->firstOrFail();
    expect($record->disk)->toBe('court_private')
        ->and($record->sha256)->toHaveLength(64)
        ->and($record->scan_status)->toBe('not_configured')
        ->and($record->related_id)->toBe(42);
});

test('an active legal hold prevents file deletion', function () {
    Storage::fake('court_private');
    $file = UploadedFile::fake()->createWithContent('held.pdf', '%PDF-1.4 held evidence');
    $path = app(SecureUploadService::class)->store($file, 'held', 'court_private');
    $record = FileSecurityRecord::query()->where('path', $path)->firstOrFail();

    $holds = app(LegalHoldService::class);
    $holds->place($record, 'Court preservation order', null);

    expect(fn () => $holds->assertFileMayBeDeleted($path))
        ->toThrow(ValidationException::class);

    $hold = $record->legalHolds()->firstOrFail();
    $holds->release($hold, 'Order discharged', null);
    $holds->assertFileMayBeDeleted($path);

    expect(true)->toBeTrue();
});
