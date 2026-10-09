<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaReportType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaReportRun extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'report_number',
        'report_type',
        'period_start',
        'period_end',
        'status',
        'generated_by',
        'generated_at',
        'file_path',
        'filters',
        'totals',
        'organization_id',
    ];

    protected $casts = [
        'report_type'  => CafeteriaReportType::class,
        'period_start' => 'date',
        'period_end'   => 'date',
        'generated_at' => 'datetime',
        'filters'      => 'array',
        'totals'       => 'array',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
