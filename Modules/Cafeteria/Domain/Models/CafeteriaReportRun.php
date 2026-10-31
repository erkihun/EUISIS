<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cafeteria\Domain\Enums\CafeteriaReportType;

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
        'report_type' => CafeteriaReportType::class,
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'generated_at' => 'datetime',
        'filters' => 'array',
        'totals' => 'array',
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
