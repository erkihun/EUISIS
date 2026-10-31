<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services\Settlement;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Providers\Contracts\Data\PortalActor;
use Modules\Transport\Application\Services\TransportRouteAccessService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Enums\TransportAdjustmentOrigin;
use Modules\Transport\Domain\Enums\TransportAdjustmentStatus;
use Modules\Transport\Domain\Enums\TransportInvoiceStatus;
use Modules\Transport\Domain\Enums\TransportSettlementStatus;
use Modules\Transport\Domain\Enums\TransportSyncState;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Models\TransportContract;
use Modules\Transport\Domain\Models\TransportInvoice;
use Modules\Transport\Domain\Models\TransportSettlement;
use Modules\Transport\Domain\Models\TransportSettlementAdjustment;
use Modules\Transport\Domain\Models\TransportTransaction;

/**
 * The settlement statement: verified usage for a provider and period,
 * read from immutable evidence; the amount the contract formula yields;
 * the provider's claim beside it; adjustments and disputes; and a
 * maker-checker approval. Nothing here changes a boarding.
 *
 *   compute (draft) → submitForReview → approve | reject
 *                   ↘ cancel          ↘ returnToDraft (recompute)
 *   approve → recordPayment (paid)
 */
class TransportSettlementService
{
    public function __construct(
        private readonly TransportSettlementCalculator $calculator,
        private readonly TransportInvoiceService $invoices,
        private readonly TransportRouteAccessService $routeAccess,
        private readonly TransportSettings $settings,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * Verified usage in the period, from evidence only: accepted, un-voided,
     * online or reconciled boardings on trips that were not cancelled.
     *
     * @return array<string, mixed>
     */
    public function basis(string $providerId, Carbon $from, Carbon $to, ?string $organizationId = null): array
    {
        $boardings = $this->verifiedBoardings($providerId, $from, $to, $organizationId);
        $trips = $this->tripsInPeriod($providerId, $from, $to, $organizationId);

        $byDirection = (clone $boardings)->select('transport_transactions.direction', DB::raw('COUNT(*) AS n'))->groupBy('transport_transactions.direction')->pluck('n', 'direction');
        $byRoute = (clone $boardings)->select('transport_transactions.transport_route_id', DB::raw('COUNT(*) AS n'), DB::raw('COUNT(DISTINCT transport_transactions.employee_id) AS riders'))
            ->groupBy('transport_transactions.transport_route_id')->get()->map(fn ($r): array => ['route_id' => (string) $r->transport_route_id, 'boardings' => (int) $r->n, 'riders' => (int) $r->riders])->all();
        $byDay = (clone $boardings)->select('transport_transactions.transaction_date', DB::raw('COUNT(*) AS n'), DB::raw('COUNT(DISTINCT transport_transactions.employee_id) AS riders'))
            ->groupBy('transport_transactions.transaction_date')->orderBy('transport_transactions.transaction_date')->get()
            ->map(fn ($r): array => ['date' => Carbon::parse($r->transaction_date)->toDateString(), 'boardings' => (int) $r->n, 'riders' => (int) $r->riders])->all();
        $byMethod = (clone $boardings)->select('transport_transactions.verification_method', DB::raw('COUNT(*) AS n'))->groupBy('transport_transactions.verification_method')->pluck('n', 'verification_method');

        $employeeDays = (int) DB::query()->fromSub((clone $boardings)->select('transport_transactions.employee_id', 'transport_transactions.transaction_date')->distinct(), 'd')->count();
        $completed = (clone $trips)->where('transport_trips.status', TransportTripStatus::Completed->value);
        $seatTrips = (int) (clone $completed)->selectRaw('COALESCE(SUM(COALESCE(transport_trips.capacity, transport_vehicles.capacity + COALESCE(transport_vehicles.standing_capacity, 0))), 0) AS seats')
            ->leftJoin('transport_vehicles', 'transport_vehicles.id', '=', 'transport_trips.transport_vehicle_id')->value('seats');

        $uncompleted = (clone $boardings)->whereIn('transport_trips.status', [TransportTripStatus::Boarding->value, TransportTripStatus::InProgress->value, TransportTripStatus::Planned->value, TransportTripStatus::Assigned->value])->count();
        $pendingOffline = TransportTransaction::query()->where('provider_id', $providerId)->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->where('status', TransportTransaction::STATUS_ACCEPTED)->whereNull('voided_at')->where('sync_state', TransportSyncState::OfflinePending->value)->count();

        return [
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'organization_id' => $organizationId,
            'verified_boardings' => (int) (clone $boardings)->count(),
            'unique_riders' => (int) (clone $boardings)->distinct()->count('transport_transactions.employee_id'),
            'employee_days' => $employeeDays,
            'inbound_boardings' => (int) ($byDirection['inbound'] ?? 0),
            'outbound_boardings' => (int) ($byDirection['outbound'] ?? 0),
            'manual_boardings' => (int) ($byMethod['manual_exception'] ?? 0),
            'completed_trips' => (int) (clone $completed)->count(),
            'cancelled_trips' => (int) (clone $trips)->whereIn('transport_trips.status', [TransportTripStatus::Cancelled->value, TransportTripStatus::Failed->value])->count(),
            'scheduled_trips' => (int) (clone $trips)->count(),
            'seat_trips' => $seatTrips,
            'vehicles_used' => (int) (clone $completed)->distinct()->count('transport_trips.transport_vehicle_id'),
            'by_route' => $byRoute,
            'by_day' => $byDay,
            'data_quality' => [
                'boardings_on_uncompleted_trips' => (int) $uncompleted,
                'offline_pending_boardings' => $pendingOffline,
            ],
        ];
    }

    /**
     * Compute (or recompute while draft) the statement for a provider,
     * contract and period. Claims the evidence rows so they are settled once.
     */
    public function compute(string $providerId, TransportContract $contract, Carbon $from, Carbon $to, User $actor, ?TransportInvoice $invoice = null, ?TransportSettlement $existing = null, ?Request $request = null): TransportSettlement
    {
        if ($contract->provider_id !== $providerId) {
            throw new DomainException(__('transport.own_data_only'));
        }
        if (! $contract->status->isBinding() || $contract->effective_from->gt($from) || ($contract->effective_to !== null && $contract->effective_to->lt($to))) {
            throw new DomainException(__('transport.contract_not_covering'));
        }
        if ($invoice !== null && $invoice->provider_id !== $providerId) {
            throw new DomainException(__('transport.own_data_only'));
        }

        return DB::transaction(function () use ($providerId, $contract, $from, $to, $actor, $invoice, $existing, $request): TransportSettlement {
            if ($existing !== null) {
                $existing = TransportSettlement::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
                if ($existing->status !== TransportSettlementStatus::Draft) {
                    throw new DomainException(__('transport.settlement_not_draft'));
                }
                TransportTransaction::query()->where('transport_settlement_id', $existing->id)->update(['transport_settlement_id' => null]);
            } else {
                $overlap = TransportSettlement::query()->where('provider_id', $providerId)->where('transport_contract_id', $contract->id)
                    ->whereNotIn('status', [TransportSettlementStatus::Cancelled->value, TransportSettlementStatus::Rejected->value])
                    ->whereDate('period_start', '<=', $to->toDateString())->whereDate('period_end', '>=', $from->toDateString())->exists();
                if ($overlap) {
                    throw new DomainException(__('transport.settlement_period_overlaps'));
                }
            }

            $basis = $this->basis($providerId, $from, $to, $contract->organization_id);
            $snapshot = $contract->snapshot();
            $calc = $this->calculator->calculate($snapshot, $basis);

            $settlement = $existing ?? new TransportSettlement(['settlement_number' => $this->newNumber()]);
            $settlement->fill([
                'provider_id' => $providerId,
                'transport_contract_id' => $contract->id,
                'transport_invoice_id' => $invoice?->id ?? $settlement->transport_invoice_id,
                'organization_id' => $contract->organization_id,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => TransportSettlementStatus::Draft,
                'contract_snapshot' => $snapshot,
                'basis' => $basis + ['lines' => $calc['lines']],
                'verified_boardings' => $basis['verified_boardings'],
                'unique_riders' => $basis['unique_riders'],
                'employee_days' => $basis['employee_days'],
                'completed_trips' => $basis['completed_trips'],
                'cancelled_trips' => $basis['cancelled_trips'],
                'currency_code' => $contract->currency_code,
                'calculated_amount' => $calc['amount'],
                'claimed_amount' => $invoice?->claimed_amount !== null ? (string) $invoice->claimed_amount : $settlement->claimed_amount,
                'computed_by' => $actor->id,
                'computed_at' => now(),
                'policy_gaps' => $calc['gaps'],
            ]);
            $settlement->save();

            // Claim the evidence: settled rows cannot be voided or settled again.
            $this->verifiedBoardings($providerId, $from, $to, $contract->organization_id)->whereNull('transport_transactions.transport_settlement_id')
                ->update(['transport_transactions.transport_settlement_id' => $settlement->id]);

            if ($invoice !== null && $invoice->transport_settlement_id !== $settlement->id) {
                $invoice->forceFill(['transport_settlement_id' => $settlement->id])->save();
                if ($invoice->status->canTransitionTo(TransportInvoiceStatus::UnderReview)) {
                    $this->invoices->takeUnderReview($invoice, $actor, $request);
                }
            }

            $this->refreshTotals($settlement);

            $this->audit->execute(AuditEventType::TransportSettlementComputed, $actor, $settlement, $contract->organization_id,
                newValues: ['settlement_number' => $settlement->settlement_number, 'provider_id' => $providerId, 'contract_version' => $contract->version_no, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
                    'verified_boardings' => $basis['verified_boardings'], 'calculated_amount' => $calc['amount'], 'claimed_amount' => $settlement->claimed_amount, 'policy_gaps' => $calc['gaps']], request: $request);

            return $settlement->refresh();
        });
    }

    public function submitForReview(TransportSettlement $settlement, User $actor, ?Request $request = null): TransportSettlement
    {
        return $this->move($settlement, TransportSettlementStatus::UnderReview, $actor, $request, AuditEventType::TransportSettlementReviewed, function (TransportSettlement $s) use ($actor): void {
            $s->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        });
    }

    public function returnToDraft(TransportSettlement $settlement, User $actor, ?Request $request = null): TransportSettlement
    {
        return $this->move($settlement, TransportSettlementStatus::Draft, $actor, $request, AuditEventType::TransportSettlementReviewed);
    }

    /**
     * Approve: the person who computed the statement cannot approve it
     * (settings `settlement_requires_distinct_approver`). Pending adjustments
     * must be decided first. A statement without a calculable amount can
     * only be approved with an explicit approved amount, which stays flagged.
     */
    public function approve(TransportSettlement $settlement, User $actor, ?Request $request = null, ?string $approvedAmount = null, ?string $notes = null): TransportSettlement
    {
        return $this->move($settlement, TransportSettlementStatus::Approved, $actor, $request, AuditEventType::TransportSettlementApproved, function (TransportSettlement $s) use ($actor, $approvedAmount, $notes): void {
            if ($this->settings->settlementRequiresDistinctApprover() && in_array($actor->id, array_filter([$s->computed_by]), true)) {
                throw new DomainException(__('transport.settlement_distinct_approver'));
            }
            if ($s->adjustments()->where('status', TransportAdjustmentStatus::Pending->value)->exists()) {
                throw new DomainException(__('transport.settlement_pending_adjustments'));
            }
            $this->refreshTotals($s, save: false);
            if ($s->calculated_amount === null) {
                if ($approvedAmount === null) {
                    throw new DomainException(__('transport.settlement_amount_undecided'));
                }
                $s->approved_amount = TransportMoney::format((int) TransportMoney::toCents($approvedAmount));
                $s->policy_gaps = array_values(array_unique([...($s->policy_gaps ?? []), 'APPROVED_AMOUNT_ENTERED_MANUALLY']));
            }
            $s->forceFill(['approved_by' => $actor->id, 'approved_at' => now(), 'notes' => $notes ?? $s->notes]);
            if ($s->invoice !== null) {
                $this->invoices->markApproved($s->invoice, $actor);
            }
        });
    }

    public function reject(TransportSettlement $settlement, string $reason, User $actor, ?Request $request = null): TransportSettlement
    {
        if (trim($reason) === '') {
            throw new DomainException(__('transport.return_reason_required'));
        }

        return $this->move($settlement, TransportSettlementStatus::Rejected, $actor, $request, AuditEventType::TransportSettlementRejected, function (TransportSettlement $s) use ($reason): void {
            $s->forceFill(['rejected_reason' => $reason]);
            TransportTransaction::query()->where('transport_settlement_id', $s->id)->update(['transport_settlement_id' => null]);
        }, $reason);
    }

    public function cancel(TransportSettlement $settlement, User $actor, ?Request $request = null): TransportSettlement
    {
        return $this->move($settlement, TransportSettlementStatus::Cancelled, $actor, $request, AuditEventType::TransportSettlementRejected, function (TransportSettlement $s): void {
            TransportTransaction::query()->where('transport_settlement_id', $s->id)->update(['transport_settlement_id' => null]);
        });
    }

    /** Link the external finance reference; transport is not the accounting system of record. */
    public function recordPayment(TransportSettlement $settlement, string $reference, User $actor, ?Request $request = null): TransportSettlement
    {
        if (trim($reference) === '') {
            throw new DomainException(__('transport.payment_reference_required'));
        }

        return $this->move($settlement, TransportSettlementStatus::Paid, $actor, $request, AuditEventType::TransportSettlementPaymentRecorded, function (TransportSettlement $s) use ($reference, $actor): void {
            $s->forceFill(['payment_reference' => $reference, 'payment_marked_at' => now(), 'payment_marked_by' => $actor->id]);
        });
    }

    /**
     * A dispute (provider) or adjustment (organization) on a statement that
     * is not yet final. Signed amount in the statement's currency; a usage
     * dispute may carry a quantity instead.
     *
     * @param  array{kind?: string, dispute_type?: string|null, amount?: string|int|float|null, quantity?: int|null, reason: string, evidence_path?: string|null}  $data
     */
    public function requestAdjustment(TransportSettlement $settlement, TransportAdjustmentOrigin $origin, array $data, PortalActor|User $actor, ?Request $request = null): TransportSettlementAdjustment
    {
        if ($settlement->status->isFinal()) {
            throw new DomainException(__('transport.settlement_final'));
        }
        if ($actor instanceof PortalActor && $actor->providerId !== $settlement->provider_id) {
            throw new DomainException(__('transport.own_data_only'));
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw new DomainException(__('transport.return_reason_required'));
        }

        $adjustment = TransportSettlementAdjustment::query()->create([
            'transport_settlement_id' => $settlement->id,
            'origin' => $origin,
            'kind' => $data['kind'] ?? 'financial',
            'dispute_type' => $data['dispute_type'] ?? null,
            'amount' => TransportMoney::format((int) (TransportMoney::toCents($data['amount'] ?? null) ?? 0)),
            'quantity' => $data['quantity'] ?? null,
            'reason' => $data['reason'],
            'evidence_path' => $data['evidence_path'] ?? null,
            'status' => TransportAdjustmentStatus::Pending,
            'requested_by_provider_user_id' => $actor instanceof PortalActor ? $actor->id : null,
            'requested_by_user_id' => $actor instanceof User ? $actor->id : null,
        ]);

        if ($origin === TransportAdjustmentOrigin::ProviderDispute && $settlement->invoice !== null) {
            $this->invoices->markDisputed($settlement->invoice);
        }

        $this->audit->execute(AuditEventType::TransportAdjustmentRequested, $actor instanceof User ? $actor : null, $adjustment, $settlement->organization_id,
            newValues: ['settlement_id' => $settlement->id, 'origin' => $origin->value, 'amount' => (string) $adjustment->amount, 'dispute_type' => $adjustment->dispute_type, 'provider_user_id' => $actor instanceof PortalActor ? $actor->id : null], reason: $data['reason'], request: $request);

        return $adjustment;
    }

    /** Approve or reject; the requester of an organization adjustment cannot decide it. */
    public function decideAdjustment(TransportSettlementAdjustment $adjustment, bool $approve, User $actor, ?string $notes = null, ?Request $request = null): TransportSettlementAdjustment
    {
        return DB::transaction(function () use ($adjustment, $approve, $actor, $notes, $request): TransportSettlementAdjustment {
            $settlement = TransportSettlement::query()->whereKey($adjustment->transport_settlement_id)->lockForUpdate()->firstOrFail();
            if ($settlement->status->isFinal()) {
                throw new DomainException(__('transport.settlement_final'));
            }
            if ($adjustment->status !== TransportAdjustmentStatus::Pending) {
                throw new DomainException(__('transport.adjustment_decided'));
            }
            if ($adjustment->requested_by_user_id === $actor->id) {
                throw new DomainException(__('transport.adjustment_distinct_approver'));
            }

            $adjustment->forceFill([
                'status' => $approve ? TransportAdjustmentStatus::Approved : TransportAdjustmentStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_notes' => $notes,
            ])->save();

            $this->refreshTotals($settlement);
            if ($settlement->invoice !== null && ! $settlement->adjustments()->where('status', TransportAdjustmentStatus::Pending->value)->exists()) {
                $this->invoices->resumeReview($settlement->invoice);
            }

            $this->audit->execute(AuditEventType::TransportAdjustmentDecided, $actor, $adjustment, $settlement->organization_id,
                newValues: ['status' => $adjustment->status->value, 'amount' => (string) $adjustment->amount, 'settlement_id' => $settlement->id], reason: $notes, request: $request);

            return $adjustment;
        });
    }

    /** Claimed vs verified vs calculated vs adjusted vs approved, as the reconciliation screen shows it. @return array<string, mixed> */
    public function reconciliation(TransportSettlement $settlement): array
    {
        $claimed = TransportMoney::toCents($settlement->claimed_amount !== null ? (string) $settlement->claimed_amount : null);
        $calculated = TransportMoney::toCents($settlement->calculated_amount !== null ? (string) $settlement->calculated_amount : null);
        $adjustments = (int) TransportMoney::toCents((string) $settlement->adjustment_total);
        $invoice = $settlement->invoice;

        return [
            'claimed_amount' => TransportMoney::format($claimed),
            'calculated_amount' => TransportMoney::format($calculated),
            'difference' => $claimed !== null && $calculated !== null ? TransportMoney::format($claimed - $calculated) : null,
            'adjustment_total' => TransportMoney::format($adjustments),
            'adjusted_amount' => $calculated !== null ? TransportMoney::format($calculated + $adjustments) : null,
            'approved_amount' => $settlement->approved_amount !== null ? (string) $settlement->approved_amount : null,
            'claimed_boardings' => $invoice?->claimed_boardings,
            'verified_boardings' => $settlement->verified_boardings,
            'boardings_difference' => $invoice?->claimed_boardings !== null ? $invoice->claimed_boardings - $settlement->verified_boardings : null,
            'claimed_trips' => $invoice?->claimed_trips,
            'completed_trips' => $settlement->completed_trips,
            'claimed_employee_days' => $invoice?->claimed_employee_days,
            'employee_days' => $settlement->employee_days,
            'disputed' => $settlement->adjustments()->where('origin', TransportAdjustmentOrigin::ProviderDispute->value)->where('status', TransportAdjustmentStatus::Pending->value)->count(),
            'policy_gaps' => $settlement->policy_gaps ?? [],
        ];
    }

    /** adjustment_total and the approved-amount preview from approved adjustments. */
    public function refreshTotals(TransportSettlement $settlement, bool $save = true): void
    {
        $approved = $settlement->adjustments()->where('status', TransportAdjustmentStatus::Approved->value)->get();
        $total = 0;
        foreach ($approved as $adjustment) {
            $total += (int) TransportMoney::toCents((string) $adjustment->amount);
        }
        $calculated = TransportMoney::toCents($settlement->calculated_amount !== null ? (string) $settlement->calculated_amount : null);
        $settlement->adjustment_total = TransportMoney::format($total);
        if ($calculated !== null) {
            $settlement->approved_amount = TransportMoney::format($calculated + $total);
        }
        if ($save) {
            $settlement->save();
        }
    }

    /** @param  callable(TransportSettlement): void|null  $apply */
    private function move(TransportSettlement $settlement, TransportSettlementStatus $to, User $actor, ?Request $request, AuditEventType $event, ?callable $apply = null, ?string $reason = null): TransportSettlement
    {
        return DB::transaction(function () use ($settlement, $to, $actor, $request, $event, $apply, $reason): TransportSettlement {
            $locked = TransportSettlement::query()->with('invoice')->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->canTransitionTo($to)) {
                throw new DomainException(__('transport.settlement_transition_invalid', ['from' => $locked->status->value, 'to' => $to->value]));
            }
            if ($apply !== null) {
                $apply($locked);
            }
            $locked->status = $to;
            $locked->save();

            $this->audit->execute($event, $actor, $locked, $locked->organization_id,
                newValues: ['status' => $to->value, 'settlement_number' => $locked->settlement_number, 'approved_amount' => $locked->approved_amount !== null ? (string) $locked->approved_amount : null], reason: $reason, request: $request);

            return $locked;
        });
    }

    private function verifiedBoardings(string $providerId, Carbon $from, Carbon $to, ?string $organizationId): QueryBuilder
    {
        $query = DB::table('transport_transactions')
            ->join('transport_trips', 'transport_trips.id', '=', 'transport_transactions.transport_trip_id')
            ->where('transport_transactions.provider_id', $providerId)
            ->where('transport_transactions.status', TransportTransaction::STATUS_ACCEPTED)
            ->whereNull('transport_transactions.voided_at')
            ->whereIn('transport_transactions.sync_state', TransportSyncState::settleable())
            ->whereBetween('transport_transactions.transaction_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->whereNotIn('transport_trips.status', [TransportTripStatus::Cancelled->value, TransportTripStatus::Failed->value]);

        if ($organizationId !== null) {
            // The organization at boarding time, from the snapshot each boarding keeps.
            $query->whereIn('transport_transactions.employee_id', $this->employeesOf($organizationId));
        }

        return $query;
    }

    private function tripsInPeriod(string $providerId, Carbon $from, Carbon $to, ?string $organizationId): QueryBuilder
    {
        $query = DB::table('transport_trips')
            ->where('transport_trips.provider_id', $providerId)
            ->whereBetween('transport_trips.trip_date', [$from->toDateString(), $to->toDateString().' 23:59:59']);

        if ($organizationId !== null) {
            $query->whereIn('transport_trips.transport_route_id', DB::table('transport_routes')->select('id')->where(function ($q) use ($organizationId): void {
                $q->where('assigned_organization_id', $organizationId)->orWhereNull('assigned_organization_id')->orWhere('assigned_scope_type', 'citywide');
            }));
        }

        return $query;
    }

    /** Employees whose current placement is in the organization or its subtree. */
    private function employeesOf(string $organizationId): QueryBuilder
    {
        $ids = DB::table('organization_closure_paths')->select('descendant_organization_id')->where('ancestor_organization_id', $organizationId);

        return DB::table('employees')
            ->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
            ->whereIn('employee_assignments.organization_id', $ids)
            ->select('employees.id');
    }

    /** From the transport-settlement code rule (TST-YYYYMMDD-NNNN by default). */
    private function newNumber(): string
    {
        return app(GenerateCodeAction::class)->execute(CodeRuleEntityType::TransportSettlement, [], null, null, 'settlement_number');
    }
}
