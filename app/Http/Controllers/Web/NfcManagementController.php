<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Services\Nfc\NfcAccess;
use App\Services\Nfc\NfcCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credential lifecycle actions for one ID card.
 *
 * Every action is authorized against the card, so organization scope is
 * enforced per record. Listing screens live in NfcAdminController.
 */
class NfcManagementController extends Controller
{
    /** Lifecycle verbs and the permission each one consumes. */
    private const ACTION_PERMISSIONS = [
        'activate' => 'nfc_credentials.activate',
        'suspend' => 'nfc_credentials.suspend',
        'lost' => 'nfc_credentials.revoke',
        'revoke' => 'nfc_credentials.revoke',
        'replace' => 'nfc_credentials.replace',
    ];

    public function __construct(private readonly NfcAccess $access) {}

    /**
     * Provisioning form.
     *
     * Reports the adapter's real capability so an operator is never told that
     * cryptographic personalisation happened when no reader is connected.
     */
    public function provisionForm(Request $request, IdCard $card, NfcCredentialService $service): Response
    {
        abort_unless($this->access->allows($request->user(), 'nfc_credentials.provision', $card), 403);

        $card->load([
            'employee:id,employee_number,full_name,current_assignment_id',
            'employee.currentAssignment:id,organization_id',
            'employee.currentAssignment.organization:id,name_en,name_am',
        ]);

        return Inertia::render('IdCards/NfcProvision', [
            'card' => [
                'id' => $card->id,
                'card_number' => $card->card_number,
                'status' => $card->status?->value,
                'expires_at' => $card->expires_at?->toIso8601String(),
            ],
            'employee' => [
                'employee_number' => $card->employee?->employee_number,
                'full_name' => $card->employee?->full_name,
                'organization' => $card->employee?->currentAssignment?->organization?->only(['name_en', 'name_am']),
            ],
            'hardware' => $service->hardwareCapability(),
        ]);
    }

    public function provision(Request $request, IdCard $card, NfcCredentialService $service): RedirectResponse
    {
        abort_unless($this->access->allows($request->user(), 'nfc_credentials.provision', $card), 403);

        $data = $request->validate([
            'credential_type' => 'required|in:ndef_reference,secure_smart_card,mobile_credential',
            'key_version' => 'nullable|string|max:64',
            'key_reference' => 'nullable|string|max:255',
        ]);

        $service->provision($card, $request->user(), $data['credential_type'], $data['key_version'] ?? null, $data['key_reference'] ?? null);

        return back()->with('success', __('nfc.provisionedPending'));
    }

    public function transition(Request $request, IdCard $card, string $credential, string $action, NfcCredentialService $service): RedirectResponse
    {
        abort_unless(array_key_exists($action, self::ACTION_PERMISSIONS), 404);
        abort_unless($this->access->allows($request->user(), self::ACTION_PERMISSIONS[$action], $card), 403);

        $model = NfcCredential::query()
            ->where('id_card_id', $card->id)
            ->where('credential_id', $credential)
            ->firstOrFail();

        $service->transition($model, $action, $request->user());

        return back()->with('success', __('nfc.actionApplied'));
    }
}
