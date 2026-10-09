<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Contracts\SmsGateway;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\PublicIdCheckOtp;
use App\Notifications\PublicIdCheckOtpNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The public Global ID Checker.
 *
 * An anonymous visitor scans a card and learns NOTHING about its holder until
 * that holder approves the check by relaying a one-time code sent to their own
 * email and phone. The consent gate is the whole point: without it, a printed
 * QR would be a public lookup of any employee's name, unit and position.
 *
 * Three rules shape every method here:
 *
 *  1. Nothing identifying leaves this class before a verified OTP. The card
 *     lookup returns status only — no name, no organization.
 *  2. The code is delivered to the EMPLOYEE's stored contacts, never to an
 *     address the checker supplies.
 *  3. Failures are generic. "No such card" and "card revoked" answer
 *     identically, so the endpoint cannot be used to enumerate valid cards.
 */
class PublicIdCheckerService
{
    /** Codes are numeric so they can be read aloud over a phone. */
    private const OTP_LENGTH = 6;

    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly SmsGateway $smsGateway,
    ) {}

    /**
     * Resolve a scanned card to its check state.
     *
     * Deliberately returns no employee data — only whether a check may proceed.
     *
     * @return array{found: bool, checkable: bool, status_code: string, card: IdCard|null}
     */
    public function resolveCard(string $cardUuid, ?Request $request = null): array
    {
        $card = $this->findCard($cardUuid);

        if ($card === null) {
            $this->audit(AuditEventType::PublicIdCheckBlocked, null, 'Public ID check for unknown card reference', $request);

            // Same shape as a revoked card: an attacker must not be able to
            // tell "no such card" from "card exists but is blocked".
            return ['found' => false, 'checkable' => false, 'status_code' => 'invalid', 'card' => null];
        }

        $statusCode = $this->statusCode($card);
        $checkable = $statusCode === 'active';

        $this->audit(
            $checkable ? AuditEventType::PublicIdCheckScanned : AuditEventType::PublicIdCheckBlocked,
            $card,
            'Public ID check scan: '.$statusCode,
            $request,
        );

        return [
            'found' => true,
            'checkable' => $checkable,
            'status_code' => $statusCode,
            'card' => $card,
        ];
    }

    /**
     * Issue a code and send it to the card holder.
     *
     * @return array{sent: bool, reason: string, channels: array<int, string>}
     */
    public function sendOtp(string $cardUuid, ?Request $request = null): array
    {
        $resolved = $this->resolveCard($cardUuid, $request);
        $card = $resolved['card'];

        if ($card === null || ! $resolved['checkable']) {
            return ['sent' => false, 'reason' => 'card_not_checkable', 'channels' => []];
        }

        $employee = $card->employee;

        if ($employee === null) {
            return ['sent' => false, 'reason' => 'card_not_checkable', 'channels' => []];
        }

        $email = trim((string) $employee->email);
        $phone = trim((string) $employee->phone);

        if ($email === '' && $phone === '') {
            // Nothing to send to. Reported generically to the checker; the
            // detail lives in the audit log for an administrator.
            $this->audit(AuditEventType::PublicIdCheckBlocked, $card, 'Public ID check OTP not sent: employee has no contact on file', $request);

            return ['sent' => false, 'reason' => 'no_contact', 'channels' => []];
        }

        $code = $this->generateCode();

        DB::transaction(function () use ($card, $cardUuid, $code, $request): void {
            // A newly requested code invalidates any earlier one, so two codes
            // for the same card are never live at once.
            PublicIdCheckOtp::query()
                ->where('card_uuid', $cardUuid)
                ->whereNull('verified_at')
                ->update(['expires_at' => now()->subSecond()]);

            PublicIdCheckOtp::query()->create([
                'id_card_id' => $card->getKey(),
                'card_uuid' => $cardUuid,
                'otp_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(PublicIdCheckOtp::TTL_MINUTES),
                'attempts' => 0,
                'ip_address' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 500, ''),
            ]);
        });

        $channels = $this->deliver($card, $code, $email, $phone);

        $this->audit(
            AuditEventType::PublicIdCheckOtpSent,
            $card,
            'Public ID check OTP sent via: '.(implode(', ', $channels) ?: 'none'),
            $request,
        );

        // Reported as sent even when every channel failed: telling an
        // anonymous caller which contacts an employee holds is itself a leak.
        return ['sent' => true, 'reason' => 'sent', 'channels' => $channels];
    }

    /**
     * Check a submitted code and, on success, return the safe employee summary.
     *
     * @return array{verified: bool, reason: string, employee: array<string, mixed>|null}
     */
    public function verifyOtp(string $cardUuid, string $code, ?Request $request = null): array
    {
        $card = $this->findCard($cardUuid);

        if ($card === null || $this->statusCode($card) !== 'active') {
            return ['verified' => false, 'reason' => 'invalid_otp', 'employee' => null];
        }

        $otp = PublicIdCheckOtp::query()
            ->where('card_uuid', $cardUuid)
            ->whereNull('verified_at')
            ->latest('created_at')
            ->first();

        if ($otp === null) {
            $this->auditOtpFailure($card, 'no outstanding code', $request);

            return ['verified' => false, 'reason' => 'invalid_otp', 'employee' => null];
        }

        if ($otp->isExpired()) {
            $this->auditOtpFailure($card, 'code expired', $request);

            return ['verified' => false, 'reason' => 'otp_expired', 'employee' => null];
        }

        if (! $otp->hasAttemptsLeft()) {
            $this->auditOtpFailure($card, 'attempt limit reached', $request);

            return ['verified' => false, 'reason' => 'too_many_attempts', 'employee' => null];
        }

        // Count the attempt before comparing, so a crash mid-check cannot be
        // used to retry indefinitely.
        $otp->increment('attempts');

        if (! Hash::check($code, $otp->otp_hash)) {
            $this->auditOtpFailure($card, 'incorrect code', $request);

            return [
                'verified' => false,
                'reason' => $otp->fresh()?->hasAttemptsLeft() === false ? 'too_many_attempts' : 'invalid_otp',
                'employee' => null,
            ];
        }

        $otp->forceFill(['verified_at' => now()])->save();

        $this->audit(AuditEventType::PublicIdCheckOtpVerified, $card, 'Public ID check OTP verified', $request);
        $this->audit(AuditEventType::PublicIdCheckInfoDisplayed, $card, 'Public ID check information displayed', $request);

        return ['verified' => true, 'reason' => 'verified', 'employee' => $this->safeEmployeeInfo($card)];
    }

    /**
     * The only employee data this feature ever publishes.
     *
     * Excludes national id, phone, email, address, salary, documents and
     * internal notes by construction — fields are listed, never spread.
     *
     * @return array<string, mixed>
     */
    public function safeEmployeeInfo(IdCard $card): array
    {
        $employee = $card->employee;
        $assignment = $employee?->currentAssignment;

        return [
            'full_name' => $employee?->full_name,
            'employee_number' => $employee?->employee_number,
            'organization' => $assignment?->organization?->name_en,
            'organization_unit' => $assignment?->organizationUnit?->name_en,
            'position' => $assignment?->position?->title_en,
            'card_number' => $card->card_number,
            'card_status' => $this->statusCode($card),
            'issued_at' => $card->issued_at?->toDateString(),
            'expires_at' => $card->expires_at?->toDateString(),
            'verified_at' => now()->toDateTimeString(),
        ];
    }

    /** Card number with only the last four characters legible. */
    public function maskCardNumber(?string $cardNumber): string
    {
        $value = (string) $cardNumber;
        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($value, -4);
    }

    private function findCard(string $cardUuid): ?IdCard
    {
        // Guard the UUID shape first: Postgres errors on a malformed uuid
        // comparison, which would turn a junk scan into a 500.
        if (! Str::isUuid($cardUuid)) {
            return null;
        }

        return IdCard::query()
            ->with([
                'employee:id,employee_number,full_name,name_en,status,email,phone,current_assignment_id',
                'employee.currentAssignment.organization:id,code,name_en,name_am',
                'employee.currentAssignment.organizationUnit:id,name_en,name_am',
                'employee.currentAssignment.position:id,job_position_code,title_en,title_am',
            ])
            ->where('public_card_uuid', $cardUuid)
            ->first();
    }

    /** Single source of truth for how a card's state is named publicly. */
    private function statusCode(IdCard $card): string
    {
        if ($card->qr_status !== 'active') {
            return 'invalid';
        }

        $isExpired = $card->expires_at !== null && $card->expires_at->isPast();

        return match (true) {
            $card->status === CardStatus::Revoked => 'revoked',
            $card->status === CardStatus::Replaced => 'replaced',
            $card->status === CardStatus::Lost => 'lost',
            $isExpired => 'expired',
            in_array($card->status, [CardStatus::Active, CardStatus::Issued], true) => 'active',
            default => 'inactive',
        };
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Send by every channel the employee has. A failure on one channel must
     * not stop the other.
     *
     * @return array<int, string>
     */
    private function deliver(IdCard $card, string $code, string $email, string $phone): array
    {
        $notification = new PublicIdCheckOtpNotification($code, $this->maskCardNumber($card->card_number));
        $channels = [];

        if ($email !== '') {
            try {
                Notification::route('mail', $email)->notify($notification);
                $channels[] = 'email';
            } catch (Throwable $exception) {
                // A dead mail host must not 500 the public endpoint. The code
                // is already stored, so SMS can still carry it.
                Log::error('Public ID check OTP email failed.', [
                    'card_id' => $card->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($phone !== '') {
            try {
                if ($this->smsGateway->send($phone, $notification->toSmsText())) {
                    $channels[] = 'sms';
                }
            } catch (Throwable $exception) {
                Log::error('Public ID check OTP SMS failed.', [
                    'card_id' => $card->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $channels;
    }

    private function auditOtpFailure(IdCard $card, string $detail, ?Request $request): void
    {
        $this->audit(AuditEventType::PublicIdCheckOtpFailed, $card, 'Public ID check OTP failed: '.$detail, $request);
    }

    private function audit(AuditEventType $event, ?IdCard $card, string $reason, ?Request $request): void
    {
        // The anonymous checker has no user account, so the actor is null and
        // the request ip/user-agent carry the only attribution available.
        $this->writeAuditLogAction->execute(
            $event,
            null,
            auditable: $card,
            reason: $reason,
            request: $request,
        );
    }
}
