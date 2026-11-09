<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\IdCards\ActivateCardAction;
use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\IssueCardAction;
use App\Actions\IdCards\ReportLostOrDamagedCardAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Services\IdCards\IdCardPrintSnapshotService;
use App\Support\Demo\DemoDataset;

/**
 * One card per demo employee through the real lifecycle: request → approval
 * by a second person (card number from the code rule, random token, stable
 * PII-free QR) → print snapshot → issue → activate. Most cards stay active;
 * the exceptions are one lost card, one expired card and one card flagged for
 * reprint after a name correction.
 *
 * Printing writes the card artwork to the local disk, as in production.
 */
class DemoIdCardSeeder extends DemoSeeder
{
    public function run(
        SubmitCardRequestAction $submit,
        ApproveCardRequestAction $approve,
        IdCardPrintSnapshotService $prints,
        IssueCardAction $issue,
        ActivateCardAction $activate,
        ReportLostOrDamagedCardAction $reportLost,
        WriteAuditLogAction $audit,
    ): void {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $approver = DemoDataset::requireUser(DemoDataset::CARD_APPROVER_EMAIL);
        $exceptions = DemoDataset::cardExceptions();

        foreach (array_keys(DemoDataset::employees()) as $key) {
            $employee = DemoDataset::requireEmployee($key);
            if (IdCard::query()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $request = $submit->execute($employee, $maker, 'Synthetic demo: first ID card');
            $card = $approve->execute($request, $approver, 'Synthetic demo approval')['card'];

            $snapshot = $prints->prepare($card, $maker);
            $prints->confirm($card->fresh(), $snapshot, $maker);
            $issue->execute($card->fresh(), $maker, $employee->full_name, $employee->full_name);
            $card = $activate->execute($card->fresh(), $maker, 'Synthetic demo activation');

            match ($exceptions[$key] ?? null) {
                'lost' => $reportLost->execute($card->fresh(), 'lost', $maker, 'Synthetic demo: card reported lost'),
                'expired' => $this->expire($card->fresh(), $maker, $audit),
                'reprint_required' => $this->correctName($employee),
                default => null,
            };
        }
    }

    /**
     * No action expires a card before its date (it lapses by date), so the
     * example is dated in the past and audited as expired.
     */
    private function expire(IdCard $card, User $maker, WriteAuditLogAction $audit): void
    {
        $old = ['status' => $card->status->value, 'expires_at' => $card->expires_at?->toDateTimeString()];
        $card->forceFill(['status' => CardStatus::Expired, 'expires_at' => now()->subDay()])->save();

        $audit->execute(AuditEventType::CardExpired, $maker, $card, $card->employee?->currentAssignment?->organization_id,
            oldValues: $old, newValues: ['status' => CardStatus::Expired->value, 'expires_at' => $card->expires_at?->toDateTimeString()],
            reason: 'Synthetic demo: expired card example');
    }

    /**
     * A name correction after printing: the employee observer compares the
     * printed snapshot with the new data and flags the card for reprint.
     */
    private function correctName(Employee $employee): void
    {
        $corrected = preg_replace('/ie\b/u', 'e', (string) $employee->name_en, 1);
        if ($corrected === null || $corrected === $employee->name_en) {
            $corrected = $employee->name_en.' (corrected)';
        }

        $employee->update(['name_en' => $corrected]);
    }
}
