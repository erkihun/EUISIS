<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class FileSecurityRecord extends Model
{
    protected $table = 'court_file_security_records';

    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scanned_at' => 'immutable_datetime',
        ];
    }

    public function legalHolds(): MorphMany
    {
        return $this->morphMany(LegalHold::class, 'holdable');
    }

    public function hasActiveLegalHold(): bool
    {
        return $this->legalHolds()->whereNull('released_at')->exists();
    }
}
