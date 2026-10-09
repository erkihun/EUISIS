<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntitlementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'service_type_id',
        'service_provider_id',
        'status',
        'quota_limit',
        'quota_used',
        'effective_from',
        'effective_to',
        'rule_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntitlementStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'rule_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }
}
