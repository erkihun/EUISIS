<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Transport\TransportContractStatus;
use App\Enums\Transport\TransportSettlementModel;
use App\Enums\Transport\TransportSettlementPeriod;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One version of the agreement between an organization (or the city) and a
 * provider. Terms are approved decimal strings keyed per settlement model;
 * a statement snapshots the version it applied, so later versions never
 * rewrite history.
 */
class TransportContract extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'contract_reference', 'organization_id', 'provider_id', 'version_no', 'supersedes_contract_id', 'status', 'currency_code',
        'settlement_model', 'settlement_period', 'terms', 'service_coverage', 'agreement_path', 'terms_reference', 'effective_from',
        'effective_to', 'approved_by', 'approved_at', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'status' => TransportContractStatus::class,
            'settlement_model' => TransportSettlementModel::class,
            'settlement_period' => TransportSettlementPeriod::class,
            'terms' => 'array',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_contract_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(TransportSettlement::class, 'transport_contract_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeCovering(Builder $query, string $from, string $to): Builder
    {
        return $query->whereIn('status', array_map(fn (TransportContractStatus $s) => $s->value, array_filter(TransportContractStatus::cases(), fn ($s) => $s->isBinding())))
            ->whereDate('effective_from', '<=', $from)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $to));
    }

    /** The approved term as a decimal string, or null when the contract does not carry it. */
    public function term(string $key): ?string
    {
        $value = $this->terms[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return list<string> required term keys this version does not carry */
    public function missingTerms(): array
    {
        return array_values(array_filter($this->settlement_model->requiredTerms(), fn (string $key): bool => $this->term($key) === null));
    }

    /** What a statement keeps of the version it applied. @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'contract_reference' => $this->contract_reference,
            'version_no' => $this->version_no,
            'organization_id' => $this->organization_id,
            'provider_id' => $this->provider_id,
            'currency_code' => $this->currency_code,
            'settlement_model' => $this->settlement_model->value,
            'settlement_period' => $this->settlement_period->value,
            'terms' => $this->terms ?? [],
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
