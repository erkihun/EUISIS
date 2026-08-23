<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\AuditLog;
use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Models\CafeteriaSetting;
use CafeteriaSystem\Services\CafeteriaCalendarService;
use CafeteriaSystem\Services\CafeteriaSettingsRegistry;
use CafeteriaSystem\Services\ServeEmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scan / manual-token verification. Both paths run the same pipeline so a
 * typed token can never bypass a check the camera path enforces.
 */
class ScanController extends Controller
{
    public function __construct(private readonly ServeEmployeeService $serveEmployeeService) {}

    public function index(Request $request): Response
    {
        $user = $request->user('cafeteria');
        $provider = $user->provider;
        $cafeteria = $user->cafeteria
            ?? Cafeteria::query()->whereIn('id', $user->accessibleCafeteriaIds())->first();

        // Organizations this cafeteria may serve today, shown on the provider
        // card so an operator can see the scope before scanning.
        $organizations = $cafeteria === null ? [] : CafeteriaOrganizationAssignment::query()
            ->where('cafeteria_id', $cafeteria->id)
            ->effectiveOn()
            ->orderBy('organization_name_snapshot')
            ->get(['organization_code', 'organization_name_snapshot'])
            ->map(fn (CafeteriaOrganizationAssignment $assignment): array => [
                'code' => $assignment->organization_code,
                'name' => $assignment->organization_name_snapshot,
            ])->all();

        return Inertia::render('Scan/Index', [
            // Fixed per installation — the operator does not choose a provider.
            'provider' => $provider === null ? null : [
                'id' => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
            ],
            'organizations' => $organizations,
            // Day statuses that colour the calendar and drive its legend.
            'calendar_days' => app(CafeteriaCalendarService::class)->days(
                $user->provider_id,
                $cafeteria?->id,
                now()->startOfMonth()->subMonth(),
                now()->endOfMonth()->addMonth(),
            ),
            'usage_modes' => ['single_day', 'use_remaining_week'],
            'default_usage_mode' => $this->defaultUsageMode($user->provider_id),
            // Seeds the "served today" column so a page reload keeps context.
            'cafeteria' => $cafeteria === null ? null : [
                'id' => $cafeteria->id,
                'code' => $cafeteria->code,
                'name' => $cafeteria->name,
            ],
            'today_scans' => CafeteriaServiceTransaction::query()
                ->when($cafeteria !== null, fn ($q) => $q->where('cafeteria_id', $cafeteria->id))
                ->where('status', 'served')
                ->whereDate('served_at', now()->toDateString())
                ->orderByDesc('served_at')
                ->get(['transaction_number', 'employee_number', 'employee_name', 'served_at'])
                ->map(fn (CafeteriaServiceTransaction $transaction): array => [
                    'transaction_number' => $transaction->transaction_number,
                    'employee_number' => $transaction->employee_number,
                    'employee_name' => $transaction->employee_name,
                    'served_at' => $transaction->served_at?->format('H:i:s'),
                ])->all(),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Accepts either the raw UUID or the full verification URL.
            'card_token' => ['required', 'string', 'max:512'],
            'usage_mode' => ['nullable', 'in:single_day,use_remaining_week'],
        ]);

        $user = $request->user('cafeteria');

        // Role gate: report viewers may look but never serve.
        abort_unless($user->canServe(), 403);

        // Scope gate: a user may only serve at a cafeteria they are bound to.
        $cafeteria = $user->cafeteria
            ?? Cafeteria::query()->whereIn('id', $user->accessibleCafeteriaIds())->first();

        if ($cafeteria === null) {
            return response()->json([
                'served' => false,
                'result_code' => 'no_cafeteria_assigned',
                'message_key' => 'cafeteria.noCafeteriaAssigned',
                'employee' => [],
                'transaction_number' => null,
            ], 422);
        }

        $token = $this->extractUuid($validated['card_token']);

        $result = $this->serveEmployeeService->serve(
            $token,
            $cafeteria,
            (string) $user->getKey(),
            'meal',
            $validated['usage_mode'] ?? $this->defaultUsageMode($user->provider_id),
        );

        // Failed verifications are audited too — they are the security signal.
        AuditLog::query()->create([
            'cafeteria_user_id' => $user->getKey(),
            'event_type' => $result['served'] ? 'service_served' : 'service_denied',
            'description' => $result['result_code'],
            'ip_address' => $request->ip(),
            'metadata' => ['employee_number' => $result['snapshot']['employee_number'] ?? null],
            'occurred_at' => now(),
        ]);

        return response()->json([
            'served' => $result['served'],
            'result_code' => $result['result_code'],
            'message_key' => $result['message_key'],
            'employee' => $result['snapshot'],
            'transaction_number' => $result['transaction']?->transaction_number,
        ], $result['served'] ? 200 : 422);
    }

    /**
     * The provider's configured default usage mode, falling back to the
     * registry default when nothing has been saved.
     */
    private function defaultUsageMode(?string $providerId): string
    {
        $stored = CafeteriaSetting::query()
            ->where('provider_id', $providerId)
            ->where('key', 'default_usage_mode')
            ->value('value');

        $mode = (string) ($stored
            ?? CafeteriaSettingsRegistry::definition()['scan']['default_usage_mode']['default']
            ?? 'single_day');

        return in_array($mode, ['single_day', 'use_remaining_week'], true) ? $mode : 'single_day';
    }

    /** QR encodes `https://<euisis>/verify/card/<uuid>`; accept URL or bare uuid. */
    private function extractUuid(string $value): string
    {
        if (preg_match('#([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $value, $matches) === 1) {
            return $matches[1];
        }

        return trim($value);
    }
}
