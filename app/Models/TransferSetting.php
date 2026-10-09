<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferCardReprintPolicy;
use App\Enums\TransferServiceRecalculationPolicy;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferSetting extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'require_same_position',
        'require_same_grade',
        'require_same_salary',
        'allow_cross_institution',
        'allow_exceptional_override',
        'override_approver_roles',
        'required_documents',
        'minimum_service_months',
        'releasing_consent_required',
        'receiving_consent_required',
        'final_approval_required',
        'card_reprint_policy',
        'service_recalculation_policy',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'require_same_position' => 'boolean',
            'require_same_grade' => 'boolean',
            'require_same_salary' => 'boolean',
            'allow_cross_institution' => 'boolean',
            'allow_exceptional_override' => 'boolean',
            'override_approver_roles' => 'array',
            'required_documents' => 'array',
            'minimum_service_months' => 'integer',
            'releasing_consent_required' => 'boolean',
            'receiving_consent_required' => 'boolean',
            'final_approval_required' => 'boolean',
            'card_reprint_policy' => TransferCardReprintPolicy::class,
            'service_recalculation_policy' => TransferServiceRecalculationPolicy::class,
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Return the singleton settings row, creating defaults if absent. */
    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([
            'require_same_position' => false,
            'require_same_grade' => false,
            'require_same_salary' => false,
            'allow_cross_institution' => true,
            'allow_exceptional_override' => false,
            'override_approver_roles' => null,
            'required_documents' => null,
            'minimum_service_months' => 0,
            'releasing_consent_required' => true,
            'receiving_consent_required' => true,
            'final_approval_required' => false,
            'card_reprint_policy' => TransferCardReprintPolicy::RequestReprint,
            'service_recalculation_policy' => TransferServiceRecalculationPolicy::RecalculateFromEffective,
        ]);
    }
}
