<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use Modules\Court\Domain\Models\FileSecurityRecord;
use Modules\Court\Domain\Models\LegalHold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class LegalHoldService
{
    public function place(Model $record, string $reason, ?int $userId): LegalHold
    {
        return DB::transaction(function () use ($record, $reason, $userId): LegalHold {
            return $record->morphMany(LegalHold::class, 'holdable')->create([
                'public_id' => (string) Str::uuid7(),
                'reason' => trim($reason),
                'placed_by_user_id' => $userId,
                'placed_at' => now(),
            ]);
        });
    }

    public function release(LegalHold $hold, string $reason, ?int $userId): void
    {
        $hold->update([
            'released_by_user_id' => $userId,
            'released_at' => now(),
            'release_reason' => trim($reason),
        ]);
    }

    public function assertFileMayBeDeleted(string $path): void
    {
        $record = FileSecurityRecord::query()->where('path', $path)->first();

        if ($record?->hasActiveLegalHold()) {
            throw ValidationException::withMessages([
                'file' => __('This file is under legal hold and cannot be deleted.'),
            ]);
        }
    }
}
