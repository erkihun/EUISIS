<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\IdCard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardPublicVerifyController extends Controller
{
    /**
     * Public card verification page — linked from the QR printed on physical cards.
     *
     * Shows a minimal, safe result:
     *  - Card valid / invalid
     *  - Card status (active, expired, revoked …)
     *  - Masked organization name (never employee PII)
     *
     * Does NOT expose: employee name, national ID, phone, service balances,
     * entitlement list, or raw token data.
     */
    public function __invoke(Request $request, string $publicCardUuid): Response
    {
        $card = IdCard::query()
            ->with(['employee.currentAssignment.organization'])
            ->where('public_card_uuid', $publicCardUuid)
            ->first();

        if ($card === null || $card->qr_status !== 'active') {
            return Inertia::render('IdCards/PublicVerify', [
                'result' => [
                    'valid'        => false,
                    'status_code'  => 'invalid',
                    'organization' => null,
                ],
            ]);
        }

        $activeStatuses = [CardStatus::Active, CardStatus::Issued];
        $isActive       = in_array($card->status, $activeStatuses, true);
        $isExpired      = $card->expires_at !== null && $card->expires_at->isPast();

        $statusCode = match (true) {
            $card->status === CardStatus::Revoked  => 'revoked',
            $card->status === CardStatus::Replaced => 'replaced',
            $card->status === CardStatus::Lost     => 'lost',
            $isExpired                              => 'expired',
            $isActive                               => 'active',
            default                                 => 'inactive',
        };

        $org = $card->employee?->currentAssignment?->organization;

        return Inertia::render('IdCards/PublicVerify', [
            'result' => [
                'valid'        => $isActive && ! $isExpired,
                'status_code'  => $statusCode,
                'organization' => $org?->name_en,
                'card_number'  => $card->card_number,
                'expires_at'   => $card->expires_at?->toDateString(),
            ],
        ]);
    }
}
