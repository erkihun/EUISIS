<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CafeteriaProvider;
use App\Models\ExternalApplication;
use App\Models\Organization;
use App\Models\ServiceProvider;
use App\Models\ServiceTerminal;
use App\Models\ServiceType;
use App\Services\Nfc\NfcAccess;
use App\Services\Nfc\NfcAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Terminal registry CRUD.
 *
 * A terminal is the physical reader that presents credentials to the
 * verification API, so registering one is an authorization decision: it binds a
 * reader to an external application. Forms never accept or return private key
 * material — only a certificate reference, which is surfaced as a fingerprint.
 */
class NfcTerminalController extends Controller
{
    private const TYPES = ['cafeteria', 'transport', 'verification', 'access', 'other'];

    private const STATUSES = ['active', 'suspended', 'revoked'];

    public function __construct(private readonly NfcAccess $access) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'terminal_type' => ['nullable', 'string', 'max:24'],
            'status' => ['nullable', 'string', 'max:24'],
            'organization_id' => ['nullable', 'uuid'],
        ]);

        $terminals = $this->scoped($request)
            ->with(['provider:id,name', 'organization:id,name_en,name_am', 'externalApplication:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $nested) => $nested
                    ->whereLike('terminal_code', "%{$search}%", caseSensitive: false)
                    ->orWhereLike('name', "%{$search}%", caseSensitive: false),
            ))
            ->when($filters['terminal_type'] ?? null, fn (Builder $query, string $type) => $query->where('terminal_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['organization_id'] ?? null, fn (Builder $query, string $id) => $query->where('organization_id', $id))
            ->orderBy('terminal_code')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceTerminal $terminal) => $this->row($terminal));

        return Inertia::render('NfcManagement/Terminals/Index', [
            'terminals' => $this->paginated($terminals),
            'filters' => $filters,
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'organizations' => $this->organizationOptions($request),
            'can' => $this->capabilities($request),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.create'), 403);

        return Inertia::render('NfcManagement/Terminals/Form', array_merge($this->formOptions($request), [
            'terminal' => null,
        ]));
    }

    public function edit(Request $request, ServiceTerminal $terminal): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.update'), 403);
        $this->assertInScope($request, $terminal);

        return Inertia::render('NfcManagement/Terminals/Form', array_merge($this->formOptions($request), [
            'terminal' => $this->row($terminal, detailed: true),
        ]));
    }

    public function show(Request $request, ServiceTerminal $terminal): Response
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.view'), 403);
        $this->assertInScope($request, $terminal);

        $terminal->load(['provider:id,name', 'cafeteriaProvider:id,name_en', 'organization:id,name_en,name_am', 'externalApplication:id,name', 'createdBy:id,name']);

        return Inertia::render('NfcManagement/Terminals/Show', [
            'terminal' => $this->row($terminal, detailed: true),
            'can' => $this->capabilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.create'), 403);

        $data = $this->validated($request);
        $this->assertOrganizationAllowed($request, $data['organization_id'] ?? null);

        $terminal = DB::transaction(function () use ($data, $request): ServiceTerminal {
            $terminal = new ServiceTerminal($data);
            $terminal->created_by = $request->user()->id;
            $terminal->save();

            app(NfcAudit::class)->record('terminal_registered', true, terminal: $terminal, request: $request, actor: $request->user());

            return $terminal;
        });

        return redirect()
            ->route('nfc-management.terminals.show', $terminal)
            ->with('success', __('nfc.terminalSaved'));
    }

    public function update(Request $request, ServiceTerminal $terminal): RedirectResponse
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.update'), 403);
        $this->assertInScope($request, $terminal);

        $data = $this->validated($request, $terminal);
        $this->assertOrganizationAllowed($request, $data['organization_id'] ?? null);

        DB::transaction(function () use ($data, $request, $terminal): void {
            $terminal->fill($data)->save();

            app(NfcAudit::class)->record('terminal_updated', true, terminal: $terminal, request: $request, actor: $request->user());
        });

        return redirect()
            ->route('nfc-management.terminals.show', $terminal)
            ->with('success', __('nfc.terminalSaved'));
    }

    public function destroy(Request $request, ServiceTerminal $terminal): RedirectResponse
    {
        abort_unless($this->access->allowsListing($request->user(), 'nfc_terminals.delete'), 403);
        $this->assertInScope($request, $terminal);

        // Registering a terminal is an authorization decision and every use of
        // it is referenced by verification logs, so a terminal is retired by
        // revoking it, never by erasing it: deleting the row would take its
        // audit history with it. A revoked terminal can no longer verify.
        DB::transaction(function () use ($request, $terminal): void {
            $terminal->update(['status' => 'revoked']);

            app(NfcAudit::class)->record('terminal_revoked', true, terminal: $terminal, request: $request, actor: $request->user());
        });

        return redirect()
            ->route('nfc-management.terminals.index')
            ->with('success', __('nfc.terminalRevoked'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ServiceTerminal $terminal = null): array
    {
        return $request->validate([
            'terminal_code' => [
                'required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/',
                Rule::unique('service_terminals', 'terminal_code')->ignore($terminal?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'terminal_type' => ['required', Rule::in(self::TYPES)],
            'status' => ['required', Rule::in(self::STATUSES)],
            'service_type' => ['nullable', 'string', 'max:64', 'exists:service_types,code'],
            'external_application_id' => ['required', Rule::exists('external_applications', 'id')->whereNull('deleted_at')],
            'provider_id' => ['nullable', 'exists:service_providers,id'],
            'cafeteria_provider_id' => ['nullable', 'exists:cafeteria_providers,id'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'certificate_reference' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function row(ServiceTerminal $terminal, bool $detailed = false): array
    {
        $row = [
            'id' => $terminal->id,
            'terminal_code' => $terminal->terminal_code,
            'name' => $terminal->name,
            'terminal_type' => $terminal->terminal_type,
            'service_type' => $terminal->service_type,
            'status' => $terminal->status,
            'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
            'provider' => $terminal->provider?->only(['id', 'name']),
            'organization' => $terminal->organization?->only(['id', 'name_en', 'name_am']),
            'external_application' => $terminal->externalApplication?->only(['id', 'name']),
        ];

        if (! $detailed) {
            return $row;
        }

        return array_merge($row, [
            'provider_id' => $terminal->provider_id,
            'cafeteria_provider_id' => $terminal->cafeteria_provider_id,
            'organization_id' => $terminal->organization_id,
            'external_application_id' => $terminal->external_application_id,
            'cafeteria_provider' => $terminal->cafeteriaProvider?->only(['id', 'name_en']),
            'created_by' => $terminal->createdBy?->name,
            'created_at' => $terminal->created_at?->toIso8601String(),
            // A fingerprint, never the reference itself.
            'certificate_fingerprint' => $terminal->certificateFingerprint(),
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'organizations' => $this->organizationOptions($request),
            'providers' => ServiceProvider::query()->orderBy('name')->get(['id', 'name'])->all(),
            'cafeteriaProviders' => CafeteriaProvider::query()->orderBy('name_en')->get(['id', 'name_en'])->all(),
            'applications' => ExternalApplication::query()->orderBy('name')->get(['id', 'name'])->all(),
            'serviceTypes' => ServiceType::query()->orderBy('code')->get(['code'])->pluck('code')->all(),
            'can' => $this->capabilities($request),
        ];
    }

    private function scoped(Request $request): Builder
    {
        $organizationIds = $this->access->organizationScope($request->user());

        return ServiceTerminal::query()->when(
            $organizationIds->isNotEmpty(),
            fn (Builder $query) => $query->whereIn('organization_id', $organizationIds),
        );
    }

    /**
     * A terminal outside the caller's organizations is reported as missing, so
     * the registry cannot be probed for codes belonging to other organizations.
     */
    private function assertInScope(Request $request, ServiceTerminal $terminal): void
    {
        $organizationIds = $this->access->organizationScope($request->user());

        abort_if(
            $organizationIds->isNotEmpty() && ! $organizationIds->contains($terminal->organization_id),
            404,
        );
    }

    /** Stops a scoped admin from assigning a terminal to an organization they do not hold. */
    private function assertOrganizationAllowed(Request $request, ?string $organizationId): void
    {
        $organizationIds = $this->access->organizationScope($request->user());

        if ($organizationIds->isEmpty()) {
            return;
        }

        abort_if($organizationId === null || ! $organizationIds->contains($organizationId), 403);
    }

    private function organizationOptions(Request $request): array
    {
        $organizationIds = $this->access->organizationScope($request->user());

        return Organization::query()
            ->select(['id', 'name_en', 'name_am'])
            ->when($organizationIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $organizationIds))
            ->orderBy('name_en')
            ->get()
            ->all();
    }

    /**
     * Laravel serialises a paginator with its metadata at the top level; the
     * client expects `data` plus a compact `meta`, matching AppDataTable.
     *
     * @return array{data: array<int, mixed>, meta: array<string, int>}
     */
    private function paginated(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function capabilities(Request $request): array
    {
        $user = $request->user();

        return [
            'viewCredentials' => $this->access->allowsListing($user, 'nfc_credentials.view'),
            'viewLogs' => $this->access->allowsListing($user, 'nfc_logs.view'),
            'viewTerminals' => $this->access->allowsListing($user, 'nfc_terminals.view'),
            'createTerminals' => $this->access->allowsListing($user, 'nfc_terminals.create'),
            'updateTerminals' => $this->access->allowsListing($user, 'nfc_terminals.update'),
            'deleteTerminals' => $this->access->allowsListing($user, 'nfc_terminals.delete'),
        ];
    }
}
