<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\PublicIdCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public Global ID Checker — no authentication.
 *
 * The page a printed QR opens. It confirms only that a card exists and is
 * active; the holder's identity appears solely after that holder relays a
 * one-time code sent to their own email and phone.
 *
 * Every response here is deliberately uniform about failure. An unknown card,
 * a revoked card and a wrong code must be indistinguishable to a caller
 * probing the endpoint, or the QR becomes an employee-enumeration oracle.
 */
class PublicIdCheckerController extends Controller
{
    public function __construct(private readonly PublicIdCheckerService $checker) {}

    /** Landing page: manual token entry or QR scan. */
    public function index(): Response
    {
        return Inertia::render('Public/IdChecker', [
            'cardUuid' => null,
            'card' => null,
        ]);
    }

    /** The page a scanned QR opens. Shows card state only — never the holder. */
    public function show(Request $request, string $cardUuid): Response
    {
        $resolved = $this->checker->resolveCard($cardUuid, $request);

        return Inertia::render('Public/IdChecker', [
            'cardUuid' => $cardUuid,
            /*
             * Set by the in-page scanner only. It lets the page request the
             * code without a second tap when someone is standing at the
             * terminal with the card in hand.
             *
             * This is a UI hint, never an authorisation: sending still goes
             * through the POST endpoint, its CSRF check and its 3-per-10-minute
             * limit. Forging `?scanned=1` therefore buys nothing that pressing
             * the button would not.
             */
            'autoSend' => $request->boolean('scanned') && $resolved['checkable'],
            // No employee fields: this is pre-consent.
            'card' => [
                'found' => $resolved['found'],
                'checkable' => $resolved['checkable'],
                'status_code' => $resolved['status_code'],
                'card_number_masked' => $resolved['card'] === null
                    ? null
                    : $this->checker->maskCardNumber($resolved['card']->card_number),
            ],
        ]);
    }

    public function sendOtp(Request $request, string $cardUuid): JsonResponse
    {
        $result = $this->checker->sendOtp($cardUuid, $request);

        if (! $result['sent']) {
            return response()->json([
                'sent' => false,
                // One message for every failure mode, so the caller cannot
                // learn whether the card exists or who holds it.
                'message_key' => 'idChecker.cannotVerifyCard',
            ], 422);
        }

        return response()->json([
            'sent' => true,
            'message_key' => 'idChecker.otpSent',
        ]);
    }

    public function verifyOtp(Request $request, string $cardUuid): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $result = $this->checker->verifyOtp($cardUuid, $validated['otp'], $request);

        if (! $result['verified']) {
            return response()->json([
                'verified' => false,
                'message_key' => match ($result['reason']) {
                    'otp_expired' => 'idChecker.otpExpired',
                    'too_many_attempts' => 'idChecker.tooManyAttempts',
                    default => 'idChecker.verificationFailed',
                },
            ], 422);
        }

        return response()->json([
            'verified' => true,
            'message_key' => 'idChecker.verificationSuccessful',
            'employee' => $result['employee'],
        ]);
    }
}
