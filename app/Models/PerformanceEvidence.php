<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\EvidenceType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence supporting a KPI actual. Files live on the private disk and are served only through an authorized route.
 */
class PerformanceEvidence extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_evidence';

    protected $attributes = ['verified' => false];

    protected $fillable = [
        'agreement_id',
        'employee_performance_item_id',
        'kpi_id',
        'daily_activity_item_id',
        'evidence_type',
        'title',
        'description',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'source_reference',
        'submitted_by',
        'submitted_at',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'evidence_type' => EvidenceType::class,
            'submitted_at' => 'datetime',
            'verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceAgreement::class, 'agreement_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceItem::class, 'employee_performance_item_id');
    }

    public function dailyActivityItem(): BelongsTo
    {
        return $this->belongsTo(DailyActivityItem::class, 'daily_activity_item_id');
    }
}
