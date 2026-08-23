<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\ApiScope;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\ApiEndpointDefinition;
use App\Models\ApiRequestLog;
use App\Models\ExternalApplication;
use App\Services\ApiEndpointCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System Settings → API Management.
 *
 * Registers the external systems allowed to call the integration API, issues
 * and revokes their tokens, and surfaces request logs. Every action is gated on
 * an `api_management.*` permission, so an Organizational Admin — who holds no
 * such permission — cannot reach any of it.
 *
 * A generated token is returned exactly once, in the redirect flash. Only the
 * Sanctum hash is persisted; there is no way to read a token back afterwards.
 */
class ApiManagementController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $writeAuditLogAction) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission('api_management.view');

        $applications = ExternalApplication::query()
            ->withCount('tokens')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'owner_institution', 'status', 'allowed_scopes', 'rate_limit_per_minute', 'last_used_at']);

        $user = Auth::user();

        return Inertia::render('ApiManagement/Index', [
            'applications' => $applications->map(fn (ExternalApplication $application): array => [
                'id' => $application->id,
                'name' => $application->name,
                'code' => $application->code,
                'owner_institution' => $application->owner_institution,
                'status' => $application->status,
                'allowed_scopes' => $application->allowed_scopes ?? [],
                'rate_limit_per_minute' => $application->rate_limit_per_minute,
                'tokens_count' => $application->tokens_count,
                'last_used_at' => $application->last_used_at?->toDateTimeString(),
            ])->all(),
            'scopes' => $this->scopeOptions(),
            'assignableEndpoints' => $this->assignableEndpoints(),
            'endpoints_count' => ApiEndpointDefinition::query()
                ->where('status', ApiEndpointDefinition::STATUS_ACTIVE)
                ->count(),
            'can' => [
                'create' => $user?->can('api_management.create') ?? false,
                'update' => $user?->can('api_management.update') ?? false,
                'delete' => $user?->can('api_management.delete') ?? false,
                'createTokens' => $user?->can('api_management.tokens.create') ?? false,
                'revokeTokens' => $user?->can('api_management.tokens.revoke') ?? false,
                'viewLogs' => $user?->can('api_management.logs.view') ?? false,
                'viewDocs' => $user?->can('api_management.docs.view') ?? false,
                'viewEndpoints' => $user?->can('api_management.endpoints.view') ?? false,
            ],
        ]);
    }

    public function show(Request $request, ExternalApplication $externalApplication): Response
    {
        $this->authorizePermission('api_management.view');

        $user = Auth::user();

        return Inertia::render('ApiManagement/Show', [
            'application' => [
                'id' => $externalApplication->id,
                'name' => $externalApplication->name,
                'code' => $externalApplication->code,
                'owner_institution' => $externalApplication->owner_institution,
                'contact_person' => $externalApplication->contact_person,
                'contact_email' => $externalApplication->contact_email,
                'callback_url' => $externalApplication->callback_url,
                'status' => $externalApplication->status,
                'allowed_scopes' => $externalApplication->allowed_scopes ?? [],
                'rate_limit_per_minute' => $externalApplication->rate_limit_per_minute,
                'allowed_ips' => $externalApplication->allowed_ips ?? [],
                'last_used_at' => $externalApplication->last_used_at?->toDateTimeString(),
            ],
            // Token metadata only — the plaintext value never leaves creation.
            'tokens' => $externalApplication->tokens()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
                ->map(fn ($token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toDateTimeString(),
                    'created_at' => $token->created_at?->toDateTimeString(),
                ])->all(),
            'scopes' => $this->scopeOptions(),
            'assignableEndpoints' => $this->assignableEndpoints(),
            'assignedEndpoints' => $externalApplication->endpoints()
                ->orderBy('uri')
                ->get()
                ->map(fn (ApiEndpointDefinition $endpoint): array => [
                    'id' => $endpoint->id,
                    'method' => $endpoint->method,
                    'uri' => $endpoint->uri,
                    'required_scope' => $endpoint->required_scope,
                    'version' => $endpoint->version,
                    'status' => $endpoint->status,
                    'is_enabled' => (bool) $endpoint->pivot->is_enabled,
                    // Last call this application made to this endpoint.
                    'last_used_at' => ApiRequestLog::query()
                        ->where('external_application_id', $externalApplication->getKey())
                        ->where('endpoint', $endpoint->uri)
                        ->max('requested_at'),
                ])->all(),
            'can' => [
                'update' => $user?->can('api_management.update') ?? false,
                'delete' => $user?->can('api_management.delete') ?? false,
                'createTokens' => $user?->can('api_management.tokens.create') ?? false,
                'revokeTokens' => $user?->can('api_management.tokens.revoke') ?? false,
            ],
        ]);
    }

    /**
     * Endpoints offered for assignment: active and documented only.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignableEndpoints(): array
    {
        return ApiEndpointDefinition::query()
            ->assignable()
            ->orderBy('uri')
            ->get()
            ->map(fn (ApiEndpointDefinition $endpoint): array => [
                'id' => $endpoint->id,
                'method' => $endpoint->method,
                'uri' => $endpoint->uri,
                'route_name' => $endpoint->route_name,
                'required_scope' => $endpoint->required_scope,
                'version' => $endpoint->version,
                'description' => $endpoint->description,
                'status' => $endpoint->status,
                'group' => $endpoint->documentationGroup(),
            ])->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('api_management.create');

        $data = $this->validatePayload($request);
        $endpointIds = $this->validatedEndpointIds($request);

        // Selecting an endpoint implies granting the scope it asserts, or the
        // application would be assigned an endpoint its token can never call.
        $data['allowed_scopes'] = $this->withRequiredScopes($data['allowed_scopes'] ?? [], $endpointIds);
        $data['created_by'] = Auth::id();

        $application = ExternalApplication::query()->create($data);
        $this->syncEndpointAssignments($application, $endpointIds);

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: 'External application created: '.$application->code,
            request: $request,
        );

        return to_route('api-management.show', $application)
            ->with('flash', ['message' => __('apiManagement.application_created'), 'type' => 'success']);
    }

    public function update(Request $request, ExternalApplication $externalApplication): RedirectResponse
    {
        $this->authorizePermission('api_management.update');

        $data = $this->validatePayload($request, $externalApplication);
        $endpointIds = $this->validatedEndpointIds($request);

        $data['allowed_scopes'] = $this->withRequiredScopes($data['allowed_scopes'] ?? [], $endpointIds);

        $externalApplication->update($data);
        $this->syncEndpointAssignments($externalApplication, $endpointIds);

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: 'External application updated: '.$externalApplication->code,
            request: $request,
        );

        return back()->with('flash', ['message' => __('apiManagement.application_updated'), 'type' => 'success']);
    }

    public function destroy(Request $request, ExternalApplication $externalApplication): RedirectResponse
    {
        $this->authorizePermission('api_management.delete');

        // Deleting the registration must also kill live access. The model soft
        // deletes, so the database cascade never fires — both the tokens and
        // the endpoint assignments have to be detached explicitly.
        $externalApplication->tokens()->delete();
        $externalApplication->endpoints()->detach();
        $externalApplication->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: 'External application deleted: '.$externalApplication->code,
            request: $request,
        );

        return to_route('api-management.index')
            ->with('flash', ['message' => __('apiManagement.application_deleted'), 'type' => 'success']);
    }

    /**
     * Issue a token. The plaintext value is flashed once and never stored.
     */
    public function storeToken(Request $request, ExternalApplication $externalApplication): RedirectResponse
    {
        $this->authorizePermission('api_management.tokens.create');

        $abilities = $externalApplication->grantableScopes();

        if ($abilities === []) {
            return back()->with('flash', ['message' => __('apiManagement.no_scopes_assigned'), 'type' => 'error']);
        }

        $token = $externalApplication->createToken(
            $request->string('name')->toString() ?: $externalApplication->code,
            $abilities,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: 'API token generated for: '.$externalApplication->code,
            request: $request,
        );

        return back()->with('flash', [
            'message' => __('apiManagement.token_generated'),
            'type' => 'success',
            // Shown once; there is no endpoint that can return it again.
            'generated_token' => $token->plainTextToken,
        ]);
    }

    public function destroyToken(Request $request, ExternalApplication $externalApplication, string $tokenId): RedirectResponse
    {
        $this->authorizePermission('api_management.tokens.revoke');

        $externalApplication->tokens()->whereKey($tokenId)->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: 'API token revoked for: '.$externalApplication->code,
            request: $request,
        );

        return back()->with('flash', ['message' => __('apiManagement.token_revoked'), 'type' => 'success']);
    }

    public function logs(Request $request): Response
    {
        $this->authorizePermission('api_management.logs.view');

        $logs = ApiRequestLog::query()
            ->with('externalApplication:id,name,code')
            ->when($request->string('application_id')->toString() !== '', fn ($query) => $query
                ->where('external_application_id', $request->string('application_id')->toString()))
            ->when($request->string('status')->toString() === 'failed', fn ($query) => $query->where('success', false))
            ->orderByDesc('requested_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('ApiManagement/Logs', [
            'logs' => $logs->through(fn (ApiRequestLog $log): array => [
                'id' => $log->id,
                'application' => $log->externalApplication?->only(['id', 'name', 'code']),
                'endpoint' => $log->endpoint,
                'method' => $log->method,
                'ip_address' => $log->ip_address,
                'status_code' => $log->status_code,
                'success' => $log->success,
                'failure_reason' => $log->failure_reason,
                'requested_at' => $log->requested_at?->toDateTimeString(),
            ]),
            'filters' => $request->only(['application_id', 'status']),
            'applications' => ExternalApplication::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function docs(): Response
    {
        $this->authorizePermission('api_management.docs.view');

        $path = base_path('docs/api-management-guide.md');

        // Documentation reads the catalog, so what is published is exactly what
        // was synced from the routes and left documented.
        $groups = ApiEndpointDefinition::query()
            ->documented()
            ->orderBy('uri')
            ->get()
            ->groupBy(fn (ApiEndpointDefinition $endpoint): string => $endpoint->documentationGroup())
            ->map(fn ($endpoints) => $endpoints->map(fn (ApiEndpointDefinition $endpoint): array => [
                'id' => $endpoint->id,
                'method' => $endpoint->method,
                'uri' => $endpoint->uri,
                'required_scope' => $endpoint->required_scope,
                'auth_required' => $endpoint->auth_required,
                'description' => $endpoint->description,
                'version' => $endpoint->version,
            ])->values());

        return Inertia::render('ApiManagement/Docs', [
            'markdown' => is_file($path) ? file_get_contents($path) : '',
            'scopes' => $this->scopeOptions(),
            'groups' => $groups,
        ]);
    }

    /**
     * Endpoint catalog: the integration surface with its scopes and status.
     */
    public function endpoints(Request $request, ApiEndpointCatalogService $catalog): Response
    {
        $this->authorizePermission('api_management.endpoints.view');

        // Rows the catalog has never seen: shown as a prompt to sync, so the
        // page is useful before the first sync rather than blank.
        $stored = ApiEndpointDefinition::query()->orderBy('uri')->orderBy('method')->get();
        $storedKeys = $stored->map(fn (ApiEndpointDefinition $e): string => $e->method.' '.$e->uri)->all();

        $unsynced = $catalog->discover()
            ->reject(fn (array $e): bool => in_array($e['method'].' '.$e['uri'], $storedKeys, true))
            ->values();

        $user = Auth::user();

        return Inertia::render('ApiManagement/Endpoints', [
            'endpoints' => $stored->map(fn (ApiEndpointDefinition $endpoint): array => [
                'id' => $endpoint->id,
                'method' => $endpoint->method,
                'uri' => $endpoint->uri,
                'route_name' => $endpoint->route_name,
                'controller_action' => $endpoint->controller_action,
                'middleware' => $endpoint->middleware ?? [],
                'auth_required' => $endpoint->auth_required,
                'required_scope' => $endpoint->required_scope,
                'rate_limit' => $endpoint->rate_limit,
                'status' => $endpoint->status,
                'description' => $endpoint->description,
                'version' => $endpoint->version,
                'is_public_documented' => $endpoint->is_public_documented,
                'last_synced_at' => $endpoint->last_synced_at?->toDateTimeString(),
            ])->all(),
            'unsynced_count' => $unsynced->count(),
            'scopes' => $this->scopeOptions(),
            'statuses' => ApiEndpointDefinition::STATUSES,
            'can' => [
                'sync' => $user?->can('api_management.endpoints.sync') ?? false,
                'update' => $user?->can('api_management.endpoints.update') ?? false,
                'viewLogs' => $user?->can('api_management.logs.view') ?? false,
            ],
        ]);
    }

    public function showEndpoint(Request $request, ApiEndpointDefinition $apiEndpointDefinition): Response
    {
        $this->authorizePermission('api_management.endpoints.view');

        $user = Auth::user();
        $canViewLogs = $user?->can('api_management.logs.view') ?? false;

        return Inertia::render('ApiManagement/EndpointShow', [
            'endpoint' => [
                'id' => $apiEndpointDefinition->id,
                'method' => $apiEndpointDefinition->method,
                'uri' => $apiEndpointDefinition->uri,
                'route_name' => $apiEndpointDefinition->route_name,
                'controller_action' => $apiEndpointDefinition->controller_action,
                'middleware' => $apiEndpointDefinition->middleware ?? [],
                'auth_required' => $apiEndpointDefinition->auth_required,
                'required_scope' => $apiEndpointDefinition->required_scope,
                'rate_limit' => $apiEndpointDefinition->rate_limit,
                'status' => $apiEndpointDefinition->status,
                'description' => $apiEndpointDefinition->description,
                'version' => $apiEndpointDefinition->version,
                'is_public_documented' => $apiEndpointDefinition->is_public_documented,
                'group' => $apiEndpointDefinition->documentationGroup(),
                'last_synced_at' => $apiEndpointDefinition->last_synced_at?->toDateTimeString(),
            ],
            'sampleRequest' => $this->sampleRequest($apiEndpointDefinition),
            // Log metadata only — never a request or response body.
            'recentLogs' => $canViewLogs
                ? $apiEndpointDefinition->logs()
                    ->with('externalApplication:id,name,code')
                    ->orderByDesc('requested_at')
                    ->limit(20)
                    ->get()
                    ->map(fn (ApiRequestLog $log): array => [
                        'id' => $log->id,
                        'application' => $log->externalApplication?->only(['id', 'name', 'code']),
                        'method' => $log->method,
                        'status_code' => $log->status_code,
                        'success' => $log->success,
                        'failure_reason' => $log->failure_reason,
                        'requested_at' => $log->requested_at?->toDateTimeString(),
                    ])->all()
                : [],
            'scopes' => $this->scopeOptions(),
            'statuses' => ApiEndpointDefinition::STATUSES,
            'can' => [
                'update' => $user?->can('api_management.endpoints.update') ?? false,
                'viewLogs' => $canViewLogs,
            ],
        ]);
    }

    /**
     * Re-read the routes and reconcile the catalog.
     */
    public function syncEndpoints(Request $request, ApiEndpointCatalogService $catalog): RedirectResponse
    {
        $this->authorizePermission('api_management.endpoints.sync');

        $summary = $catalog->sync((string) Auth::id());

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: sprintf(
                'API endpoint catalog synced: %d created, %d updated, %d deprecated.',
                $summary['created'], $summary['updated'], $summary['deprecated'],
            ),
            request: $request,
        );

        return back()->with('flash', [
            'message' => __('apiManagement.endpoints_synced', $summary),
            'type' => 'success',
        ]);
    }

    /**
     * Update the curated metadata of a catalog entry.
     *
     * Method, URI and controller action are deliberately not editable — they
     * are owned by the route table and would be overwritten on the next sync.
     */
    public function updateEndpoint(Request $request, ApiEndpointDefinition $apiEndpointDefinition): RedirectResponse
    {
        $this->authorizePermission('api_management.endpoints.update');

        $data = $request->validate([
            'required_scope' => ['nullable', Rule::in(ApiScope::values())],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(ApiEndpointDefinition::STATUSES)],
            'is_public_documented' => ['required', 'boolean'],
        ]);

        $apiEndpointDefinition->update($data);

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            Auth::user(),
            reason: 'API endpoint updated: '.$apiEndpointDefinition->method.' '.$apiEndpointDefinition->uri,
            request: $request,
        );

        return back()->with('flash', ['message' => __('apiManagement.endpoint_updated'), 'type' => 'success']);
    }

    /**
     * Illustrative request for the detail page.
     *
     * A placeholder token string is used deliberately — no real credential is
     * ever rendered into documentation.
     */
    private function sampleRequest(ApiEndpointDefinition $endpoint): string
    {
        $lines = [
            $endpoint->method.' '.$endpoint->uri.' HTTP/1.1',
            'Host: '.parse_url((string) config('app.url'), PHP_URL_HOST),
            'Authorization: Bearer <YOUR_API_TOKEN>',
            'Accept: application/json',
        ];

        if (in_array($endpoint->method, ['POST', 'PUT', 'PATCH'], true)) {
            $lines[] = 'Content-Type: application/json';

            if (in_array('api.idempotency', $endpoint->middleware ?? [], true)) {
                $lines[] = 'Idempotency-Key: <UNIQUE_KEY_PER_TRANSACTION>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Endpoint ids submitted with the form, validated against what may
     * actually be assigned.
     *
     * Restricting to active + documented rows server-side matters: the UI only
     * offers those, but a crafted request must not be able to attach a
     * deprecated or hidden endpoint.
     *
     * @return array<int, string>
     */
    private function validatedEndpointIds(Request $request): array
    {
        $assignable = ApiEndpointDefinition::query()->assignable()->pluck('id')->all();

        $validated = $request->validate([
            'endpoint_ids' => ['array'],
            'endpoint_ids.*' => [Rule::in($assignable)],
        ]);

        return array_values(array_unique($validated['endpoint_ids'] ?? []));
    }

    /**
     * Union of the chosen scopes and those the selected endpoints require.
     *
     * Auto-adding rather than rejecting: an administrator who ticks an endpoint
     * has clearly decided the application may call it, and failing the save
     * over a checkbox they did not know to tick would be pure friction.
     *
     * @param  array<int, string>  $scopes
     * @param  array<int, string>  $endpointIds
     * @return array<int, string>
     */
    private function withRequiredScopes(array $scopes, array $endpointIds): array
    {
        if ($endpointIds === []) {
            return array_values(array_unique($scopes));
        }

        $required = ApiEndpointDefinition::query()
            ->whereIn('id', $endpointIds)
            ->whereNotNull('required_scope')
            ->pluck('required_scope')
            ->all();

        return array_values(array_unique(array_merge(
            $scopes,
            // Guard against a stale scope string on a catalog row widening access.
            array_intersect($required, ApiScope::values()),
        )));
    }

    /**
     * @param  array<int, string>  $endpointIds
     */
    private function syncEndpointAssignments(ExternalApplication $application, array $endpointIds): void
    {
        $createdBy = (string) Auth::id();

        $application->endpoints()->sync(
            collect($endpointIds)
                ->mapWithKeys(fn (string $id): array => [$id => [
                    'id' => (string) Str::uuid(),
                    'is_enabled' => true,
                    'created_by' => $createdBy,
                ]])
                ->all()
        );
    }

    /** @return array<int, array{value: string, label: string}> */
    private function scopeOptions(): array
    {
        return array_map(
            static fn (ApiScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            ApiScope::cases(),
        );
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, ?ExternalApplication $application = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('external_applications', 'code')->ignore($application?->getKey()),
            ],
            'owner_institution' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'callback_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', Rule::in(['active', 'suspended', 'revoked'])],
            'allowed_scopes' => ['array'],
            'allowed_scopes.*' => [Rule::in(ApiScope::values())],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:10000'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['string', 'ip'],
        ]);
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(Auth::user()?->can($permission) ?? false, 403);
    }
}
