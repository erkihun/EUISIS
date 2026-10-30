<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services\Settlement;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Transport\Domain\Enums\TransportInvoiceStatus;
use Modules\Transport\Domain\Models\TransportInvoice;

/**
 * The provider's claim. The provider writes what it claims; the city never
 * edits those figures and the provider never touches the verified or
 * approved ones. The claim is compared to the statement in reconciliation.
 */
class TransportInvoiceService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data */
    public function draft(Provider $provider, array $data, ProviderUser $actor): TransportInvoice
    {
        $this->guardPeriod($data['period_start'], $data['period_end']);

        return TransportInvoice::query()->create([
            'invoice_number' => ($data['invoice_number'] ?? null) ?: $this->newNumber($provider, $data['period_start']),
            'provider_id' => $provider->id,
            'transport_contract_id' => $data['transport_contract_id'] ?? null,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'claimed_boardings' => $data['claimed_boardings'] ?? null,
            'claimed_trips' => $data['claimed_trips'] ?? null,
            'claimed_employee_days' => $data['claimed_employee_days'] ?? null,
            'claimed_amount' => TransportMoney::format((int) TransportMoney::toCents((string) $data['claimed_amount'])),
            'currency_code' => $data['currency_code'] ?? 'ETB',
            'status' => TransportInvoiceStatus::Draft,
            'attachment_path' => $data['attachment_path'] ?? null,
            'notes' => $data['notes'] ?? null,
            'submitted_by_provider_user_id' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(TransportInvoice $invoice, array $data, ProviderUser $actor): TransportInvoice
    {
        $this->guardOwner($invoice, $actor);
        if (! $invoice->status->isProviderEditable()) {
            throw new DomainException(__('transport.invoice_not_editable'));
        }
        if (isset($data['period_start'], $data['period_end'])) {
            $this->guardPeriod($data['period_start'], $data['period_end']);
        }
        if (array_key_exists('claimed_amount', $data)) {
            $data['claimed_amount'] = TransportMoney::format((int) TransportMoney::toCents((string) $data['claimed_amount']));
        }
        $invoice->fill(array_intersect_key($data, array_flip([
            'invoice_number', 'transport_contract_id', 'period_start', 'period_end', 'claimed_boardings', 'claimed_trips', 'claimed_employee_days', 'claimed_amount', 'attachment_path', 'notes',
        ])))->save();

        return $invoice;
    }

    public function submit(TransportInvoice $invoice, ProviderUser $actor, ?Request $request = null): TransportInvoice
    {
        $this->guardOwner($invoice, $actor);
        $this->transition($invoice, TransportInvoiceStatus::Submitted);
        $invoice->forceFill(['status' => TransportInvoiceStatus::Submitted, 'submitted_at' => now(), 'submitted_by_provider_user_id' => $actor->id, 'returned_reason' => null])->save();

        $this->audit->execute(AuditEventType::TransportInvoiceSubmitted, null, $invoice, null,
            newValues: ['provider_id' => $invoice->provider_id, 'invoice_number' => $invoice->invoice_number, 'period_start' => $invoice->period_start->toDateString(), 'period_end' => $invoice->period_end->toDateString(), 'claimed_amount' => (string) $invoice->claimed_amount, 'provider_user_id' => $actor->id], request: $request);

        return $invoice;
    }

    public function withdraw(TransportInvoice $invoice, ProviderUser $actor): TransportInvoice
    {
        $this->guardOwner($invoice, $actor);
        $this->transition($invoice, TransportInvoiceStatus::Withdrawn);
        $invoice->forceFill(['status' => TransportInvoiceStatus::Withdrawn])->save();

        return $invoice;
    }

    /** Staff: take the claim under review; the statement is computed next to it. */
    public function takeUnderReview(TransportInvoice $invoice, User $actor, ?Request $request = null): TransportInvoice
    {
        $this->transition($invoice, TransportInvoiceStatus::UnderReview);
        $invoice->forceFill(['status' => TransportInvoiceStatus::UnderReview, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
        $this->audit->execute(AuditEventType::TransportInvoiceReviewed, $actor, $invoice, null, newValues: ['status' => 'under_review'], request: $request);

        return $invoice;
    }

    public function returnToProvider(TransportInvoice $invoice, string $reason, User $actor, ?Request $request = null): TransportInvoice
    {
        if (trim($reason) === '') {
            throw new DomainException(__('transport.return_reason_required'));
        }
        $this->transition($invoice, TransportInvoiceStatus::Returned);
        $invoice->forceFill(['status' => TransportInvoiceStatus::Returned, 'returned_reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
        $this->audit->execute(AuditEventType::TransportInvoiceReviewed, $actor, $invoice, null, newValues: ['status' => 'returned'], reason: $reason, request: $request);

        return $invoice;
    }

    public function markDisputed(TransportInvoice $invoice, ?User $actor = null): TransportInvoice
    {
        // A statement computed against a submitted claim is its review: the dispute lands on a claim under review.
        if ($invoice->status === TransportInvoiceStatus::Submitted) {
            $invoice->forceFill(['status' => TransportInvoiceStatus::UnderReview])->save();
        }
        if ($invoice->status->canTransitionTo(TransportInvoiceStatus::Disputed)) {
            $invoice->forceFill(['status' => TransportInvoiceStatus::Disputed])->save();
        }

        return $invoice;
    }

    /** Called by the settlement when it is approved. */
    public function markApproved(TransportInvoice $invoice, User $actor, ?Request $request = null): TransportInvoice
    {
        if (in_array($invoice->status, [TransportInvoiceStatus::Submitted], true)) {
            $invoice->forceFill(['status' => TransportInvoiceStatus::UnderReview])->save();
        }
        $this->transition($invoice, TransportInvoiceStatus::Approved);
        $invoice->forceFill(['status' => TransportInvoiceStatus::Approved, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
        $this->audit->execute(AuditEventType::TransportInvoiceReviewed, $actor, $invoice, null, newValues: ['status' => 'approved'], request: $request);

        return $invoice;
    }

    /** Back from disputed once the dispute is decided. */
    public function resumeReview(TransportInvoice $invoice): TransportInvoice
    {
        if ($invoice->status === TransportInvoiceStatus::Disputed) {
            $invoice->forceFill(['status' => TransportInvoiceStatus::UnderReview])->save();
        }

        return $invoice;
    }

    private function transition(TransportInvoice $invoice, TransportInvoiceStatus $to): void
    {
        if (! $invoice->status->canTransitionTo($to)) {
            throw new DomainException(__('transport.invoice_transition_invalid', ['from' => $invoice->status->value, 'to' => $to->value]));
        }
    }

    private function guardOwner(TransportInvoice $invoice, ProviderUser $actor): void
    {
        if ($invoice->provider_id !== $actor->provider_id) {
            throw new DomainException(__('transport.own_data_only'));
        }
    }

    private function guardPeriod(string $start, string $end): void
    {
        if ($end < $start) {
            throw new DomainException(__('transport.period_invalid'));
        }
    }

    private function newNumber(Provider $provider, string $periodStart): string
    {
        do {
            $number = 'INV-'.$provider->provider_code.'-'.substr(str_replace('-', '', $periodStart), 0, 6).'-'.Str::upper(Str::random(4));
        } while (TransportInvoice::query()->where('provider_id', $provider->id)->where('invoice_number', $number)->exists());

        return $number;
    }
}
