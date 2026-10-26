<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Services\AuditWriter;
use App\Services\CredentialVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The public card check (EUISIS Public/IdChecker): a scanned QR shows the
 * card's state only; the holder's name, organization and position appear
 * after the holder gives the six-digit code sent to their e-mail. The Core
 * has no SMS gateway, so the code goes by e-mail only (ADR 0006 addendum).
 */
class IdCheckerController extends Controller
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /** Denials about the credential itself (ADR 0010): the card behind it is not described at all. */
    private const CREDENTIAL_DENIALS = ['CREDENTIAL_UNKNOWN', 'CREDENTIAL_REVOKED', 'CREDENTIAL_SUPERSEDED', 'CREDENTIAL_EXPIRED', 'KEY_RETIRED'];

    public function __construct(private readonly AuditWriter $audit, private readonly CredentialVerifier $verifier) {}

    public function index(): Response
    {
        return Inertia::render('Public/IdChecker', ['cardUuid' => null, 'card' => null, 'sections' => []]);
    }

    public function show(Request $request, string $cardUuid): Response
    {
        $card = $this->find($cardUuid);
        $status = $card === null ? 'invalid' : $this->status($card);
        if ($card !== null) {
            $this->audit->write('card.public_check_scanned', null, $card, null, null, ['status' => $status]);
        }

        return Inertia::render('Public/IdChecker', [
            'cardUuid' => $cardUuid,
            'autoSend' => $request->boolean('scanned') && $status === 'active',
            'card' => [
                'found' => $card !== null,
                'checkable' => $status === 'active',
                'status_code' => $status,
                'card_number_masked' => $card === null ? null : $this->mask($card->card_number),
            ],
            'sections' => [],
        ]);
    }

    public function sendOtp(string $cardUuid): JsonResponse
    {
        $card = $this->find($cardUuid);
        $email = trim((string) $card?->employee?->email);
        if ($card === null || $this->status($card) !== 'active' || $email === '') {
            return response()->json(['sent' => false, 'message_key' => 'idChecker.cannotVerifyCard'], 422);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($cardUuid), ['hash' => Hash::make($code), 'attempts' => 0], now()->addMinutes(self::TTL_MINUTES));
        try {
            Mail::raw(__('Your ID card check code is :code. It expires in :minutes minutes. Card :card.', ['code' => $code, 'minutes' => self::TTL_MINUTES, 'card' => $this->mask($card->card_number)]),
                fn ($message) => $message->to($email)->subject(__('ID card check code')));
        } catch (Throwable $exception) {
            // Reported as sent: telling an anonymous caller which contacts an employee holds is itself a leak.
            Log::error('Public ID check code e-mail failed.', ['card_id' => $card->id, 'error' => $exception->getMessage()]);
        }
        $this->audit->write('card.public_check_code_sent', null, $card, null);

        return response()->json(['sent' => true, 'message_key' => 'idChecker.otpSent']);
    }

    public function verifyOtp(Request $request, string $cardUuid): JsonResponse
    {
        $code = $request->validate(['otp' => ['required', 'string', 'regex:/^\d{6}$/']])['otp'];
        $card = $this->find($cardUuid);
        $stored = Cache::get($this->key($cardUuid));
        $fail = fn (string $key): JsonResponse => response()->json(['verified' => false, 'message_key' => $key], 422);
        if ($card === null || $this->status($card) !== 'active' || $stored === null) {
            return $fail($stored === null && $card !== null ? 'idChecker.otpExpired' : 'idChecker.verificationFailed');
        }
        if ($stored['attempts'] >= self::MAX_ATTEMPTS) {
            return $fail('idChecker.tooManyAttempts');
        }
        $stored['attempts']++;
        Cache::put($this->key($cardUuid), $stored, now()->addMinutes(self::TTL_MINUTES));
        if (! Hash::check($code, $stored['hash'])) {
            return $fail($stored['attempts'] >= self::MAX_ATTEMPTS ? 'idChecker.tooManyAttempts' : 'idChecker.verificationFailed');
        }
        Cache::forget($this->key($cardUuid));
        $this->audit->write('card.public_check_info_displayed', null, $card, null);
        $assignment = $card->employee?->currentAssignment;
        $name = fn (?object $model, string $attribute): ?string => $model === null ? null
            : (app()->getLocale() === 'am' && filled($model->{$attribute.'_am'} ?? null) ? $model->{$attribute.'_am'} : $model->{$attribute.'_en'});

        return response()->json([
            'verified' => true,
            'message_key' => 'idChecker.verificationSuccessful',
            'employee' => [
                'full_name' => $card->employee?->full_name,
                'employee_number' => $card->employee?->employee_number,
                'organization' => $name($assignment?->organization, 'name'),
                'organization_unit' => $name($assignment?->unit, 'name'),
                'position' => $name($assignment?->position, 'title'),
                'card_number' => $card->card_number,
                'card_status' => 'active',
                'issued_at' => $card->issued_at?->toDateString(),
                'expires_at' => $card->expires_at?->toDateString(),
                'verified_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    /** The card a QR's token reaches (CredentialVerifier): a revoked, replaced, expired or unknown credential reaches none. */
    private function find(string $token): ?IdCard
    {
        $result = $this->verifier->check($token);

        return in_array($result['code'], self::CREDENTIAL_DENIALS, true) ? null : $result['card'];
    }

    /** EUISIS PublicIdCheckerService::statusCode over the Core validity rule. */
    private function status(IdCard $card): string
    {
        if (($card->qr_status ?? 'active') !== 'active') {
            return 'invalid';
        }

        return match (true) {
            $card->status === IdCard::REVOKED => 'revoked',
            $card->status === IdCard::REPLACED => 'replaced',
            $card->status === IdCard::LOST => 'lost',
            $card->status === IdCard::EXPIRED || ($card->expires_at !== null && $card->expires_at->isPast()) => 'expired',
            $card->isValidCredential() => 'active',
            default => 'inactive',
        };
    }

    private function mask(?string $number): string
    {
        $value = (string) $number;
        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($value, -4);
    }

    private function key(string $cardUuid): string
    {
        return 'core.id-check.'.hash('sha256', $cardUuid);
    }
}
