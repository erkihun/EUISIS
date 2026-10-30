<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services\Settlement;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Transport\Domain\Enums\TransportContractStatus;
use Modules\Transport\Domain\Enums\TransportSettlementModel;
use Modules\Transport\Domain\Models\TransportContract;

/**
 * Versioned contracts. A draft can be edited; approval makes it the binding
 * version from its effective date and closes the previous version the day
 * before. A later version never rewrites a statement that snapshotted an
 * earlier one. Rates are whatever the approved agreement says — this
 * service validates their shape, never their value.
 */
class TransportContractService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data */
    public function createDraft(array $data, User $actor, ?Request $request = null): TransportContract
    {
        $data['terms'] = $this->cleanTerms($data['terms'] ?? []);
        $contract = TransportContract::query()->create($data + [
            'version_no' => 1,
            'status' => TransportContractStatus::Draft,
            'created_by' => $actor->id,
        ]);

        $this->audit->execute(AuditEventType::TransportContractChanged, $actor, $contract, $contract->organization_id,
            newValues: ['action' => 'created', 'contract_reference' => $contract->contract_reference, 'version_no' => 1, 'settlement_model' => $contract->settlement_model->value], request: $request);

        return $contract;
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(TransportContract $contract, array $data, User $actor, ?Request $request = null): TransportContract
    {
        if ($contract->status !== TransportContractStatus::Draft) {
            throw new DomainException(__('transport.contract_not_draft'));
        }
        if (array_key_exists('terms', $data)) {
            $data['terms'] = $this->cleanTerms($data['terms'] ?? []);
        }
        $before = $contract->only(['settlement_model', 'settlement_period', 'terms', 'effective_from', 'effective_to', 'currency_code']);
        $contract->fill($data + ['updated_by' => $actor->id])->save();

        $this->audit->execute(AuditEventType::TransportContractChanged, $actor, $contract, $contract->organization_id,
            oldValues: $before, newValues: ['action' => 'updated'] + $contract->only(['settlement_model', 'settlement_period', 'terms', 'effective_from', 'effective_to', 'currency_code']), request: $request);

        return $contract;
    }

    /** A new draft version carrying the current terms, to be edited and approved. */
    public function newVersion(TransportContract $current, User $actor, ?Request $request = null): TransportContract
    {
        if (! $current->status->isBinding() || $current->status !== TransportContractStatus::Active) {
            throw new DomainException(__('transport.contract_not_active'));
        }
        $open = TransportContract::query()->where('contract_reference', $current->contract_reference)->where('status', TransportContractStatus::Draft->value)->exists();
        if ($open) {
            throw new DomainException(__('transport.contract_draft_exists'));
        }

        $next = TransportContract::query()->create([
            'contract_reference' => $current->contract_reference,
            'organization_id' => $current->organization_id,
            'provider_id' => $current->provider_id,
            'version_no' => $current->version_no + 1,
            'supersedes_contract_id' => $current->id,
            'status' => TransportContractStatus::Draft,
            'currency_code' => $current->currency_code,
            'settlement_model' => $current->settlement_model,
            'settlement_period' => $current->settlement_period,
            'terms' => $current->terms,
            'service_coverage' => $current->service_coverage,
            'terms_reference' => $current->terms_reference,
            'effective_from' => today()->toDateString(),
            'created_by' => $actor->id,
        ]);

        $this->audit->execute(AuditEventType::TransportContractChanged, $actor, $next, $next->organization_id,
            newValues: ['action' => 'new_version', 'version_no' => $next->version_no, 'supersedes' => $current->id], request: $request);

        return $next;
    }

    /** Approve: the draft becomes binding; the previous version ends the day before. */
    public function approve(TransportContract $contract, User $actor, ?Request $request = null): TransportContract
    {
        return DB::transaction(function () use ($contract, $actor, $request): TransportContract {
            $locked = TransportContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->canTransitionTo(TransportContractStatus::Active)) {
                throw new DomainException(__('transport.contract_not_draft'));
            }
            if ($locked->created_by === $actor->id && config('transport.contract_requires_distinct_approver', true)) {
                throw new DomainException(__('transport.contract_distinct_approver'));
            }
            if ($locked->missingTerms() !== [] && $locked->settlement_model !== TransportSettlementModel::OtherApprovedModel) {
                throw new DomainException(__('transport.contract_terms_missing', ['terms' => implode(', ', $locked->missingTerms())]));
            }

            $previous = TransportContract::query()
                ->where('contract_reference', $locked->contract_reference)
                ->where('status', TransportContractStatus::Active->value)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->get();
            foreach ($previous as $old) {
                $endsOn = $locked->effective_from->copy()->subDay();
                if ($endsOn->lt($old->effective_from)) {
                    throw new DomainException(__('transport.contract_overlaps'));
                }
                $old->forceFill(['status' => TransportContractStatus::Superseded, 'effective_to' => $endsOn->toDateString()])->save();
            }

            $locked->forceFill(['status' => TransportContractStatus::Active, 'approved_by' => $actor->id, 'approved_at' => now()])->save();

            $this->audit->execute(AuditEventType::TransportContractChanged, $actor, $locked, $locked->organization_id,
                newValues: ['action' => 'approved', 'version_no' => $locked->version_no, 'superseded' => $previous->pluck('id')->all(), 'terms' => $locked->terms], request: $request);

            return $locked;
        });
    }

    public function end(TransportContract $contract, Carbon $on, User $actor, ?Request $request = null): TransportContract
    {
        if (! $contract->status->canTransitionTo(TransportContractStatus::Ended)) {
            throw new DomainException(__('transport.contract_not_active'));
        }
        $contract->forceFill(['status' => TransportContractStatus::Ended, 'effective_to' => $on->toDateString(), 'updated_by' => $actor->id])->save();
        $this->audit->execute(AuditEventType::TransportContractChanged, $actor, $contract, $contract->organization_id, newValues: ['action' => 'ended', 'effective_to' => $on->toDateString()], request: $request);

        return $contract;
    }

    public function cancel(TransportContract $contract, User $actor, ?Request $request = null): TransportContract
    {
        if (! $contract->status->canTransitionTo(TransportContractStatus::Cancelled)) {
            throw new DomainException(__('transport.contract_not_draft'));
        }
        $contract->forceFill(['status' => TransportContractStatus::Cancelled, 'updated_by' => $actor->id])->save();
        $this->audit->execute(AuditEventType::TransportContractChanged, $actor, $contract, $contract->organization_id, newValues: ['action' => 'cancelled'], request: $request);

        return $contract;
    }

    /** The binding version for a provider (and organization) covering a period, else null. */
    public function bindingFor(string $providerId, ?string $organizationId, string $from, string $to): ?TransportContract
    {
        return TransportContract::query()
            ->where('provider_id', $providerId)
            ->covering($from, $to)
            ->where(fn ($q) => $organizationId === null ? $q->whereNull('organization_id') : $q->where('organization_id', $organizationId))
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * Only known term keys, as two-decimal strings; blanks are dropped.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, string>
     */
    private function cleanTerms(array $terms): array
    {
        $clean = [];
        foreach (TransportSettlementModel::allTerms() as $key) {
            $value = $terms[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $cents = TransportMoney::toCents(is_numeric($value) ? number_format((float) $value, 2, '.', '') : (string) $value);
            if ($cents === null || $cents < 0) {
                throw new DomainException(__('transport.contract_term_invalid', ['term' => $key]));
            }
            $clean[$key] = (string) TransportMoney::format($cents);
        }

        return $clean;
    }
}
