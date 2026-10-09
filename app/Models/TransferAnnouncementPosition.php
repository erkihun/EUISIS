<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferAnnouncementPosition extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'transfer_announcement_id',
        'organization_id',
        'position_id',
        'grade_level',
        'salary_min',
        'salary_max',
        'vacancy_count',
    ];

    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'vacancy_count' => 'integer',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(TransferAnnouncement::class, 'transfer_announcement_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(TransferApplication::class, 'announcement_position_id');
    }
}
