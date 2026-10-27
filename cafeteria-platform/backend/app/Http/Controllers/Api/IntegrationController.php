<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureApiClient;
use App\Http\Resources\Integration\AssignmentResource;
use App\Http\Resources\Integration\EmployeeResource;
use App\Http\Resources\Integration\OrganizationResource;
use App\Http\Resources\Integration\PositionResource;
use App\Models\ApiClient;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\Position;
use App\Services\CredentialVerifier;
use App\Services\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The versioned integration API (/api/v1/integration) for the systems that
 * consume the Core. Every route needs one client scope (EnsureApiClient) and
 * reads only inside the client's organizations; lists are paginated (at most
 * 100 per page) and filter by status, organization and updated_since. Records
 * outside the client's organizations are not found, never forbidden, so
 * their existence does not leak.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function organizations(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request);
        $query = $this->scope->apply(Organization::query()->with('type:id,code'), $this->client($request), 'id')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['updated_since'] ?? null, fn (Builder $q, string $since) => $q->where('updated_at', '>=', $since))
            ->orderBy('code');
        $page = $query->paginate($filters['per_page'] ?? 50)->withQueryString();
        $parents = $this->parents($page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Organization $organization) => $organization->setAttribute('parent_id', $parents[$organization->id] ?? null));

        return OrganizationResource::collection($page);
    }

    public function organization(Request $request, string $organization): OrganizationResource
    {
        $found = $this->scope->apply(Organization::query()->with('type:id,code'), $this->client($request), 'id')->findOrFail($organization);
        $found->setAttribute('parent_id', $this->parents([$found->id])[$found->id] ?? null);

        return new OrganizationResource($found);
    }

    public function positions(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request);
        $query = $this->scope->apply(Position::query()->withCount('holders'), $this->client($request))
            ->when($filters['organization_id'] ?? null, fn (Builder $q, string $id) => $q->where('organization_id', $id))
            ->when(array_key_exists('status', $filters), fn (Builder $q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($filters['updated_since'] ?? null, fn (Builder $q, string $since) => $q->where('updated_at', '>=', $since))
            ->orderBy('job_position_code');

        return PositionResource::collection($query->paginate($filters['per_page'] ?? 50)->withQueryString());
    }

    public function position(Request $request, string $position): PositionResource
    {
        return new PositionResource($this->scope->apply(Position::query()->withCount('holders'), $this->client($request))->findOrFail($position));
    }

    public function employees(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request);
        $query = $this->employeeQuery($request)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['organization_id'] ?? null, fn (Builder $q, string $id) => $q->whereHas('currentAssignment', fn (Builder $a) => $a->where('organization_id', $id)))
            ->when($filters['updated_since'] ?? null, fn (Builder $q, string $since) => $q->where('updated_at', '>=', $since))
            ->orderBy('employee_number');

        return EmployeeResource::collection($query->paginate($filters['per_page'] ?? 50)->withQueryString());
    }

    public function employee(Request $request, string $employee): EmployeeResource
    {
        return new EmployeeResource($this->employeeQuery($request)->findOrFail($employee));
    }

    public function assignment(Request $request, string $employee): JsonResponse
    {
        $found = $this->employeeQuery($request)->findOrFail($employee);

        return response()->json(['data' => $found->currentAssignment === null ? null : new AssignmentResource($found->currentAssignment)]);
    }

    public function assignmentHistory(Request $request, string $employee): AnonymousResourceCollection
    {
        $found = $this->employeeQuery($request)->findOrFail($employee);

        return AssignmentResource::collection(EmployeeAssignment::query()->where('employee_id', $found->id)
            ->orderByDesc('effective_from')->orderByDesc('created_at')->limit(200)->get());
    }

    /** Whether the person may receive services now: employment, placement and card, with denial codes. */
    public function employeeStatus(Request $request, string $employee): JsonResponse
    {
        $found = $this->employeeQuery($request)->findOrFail($employee);
        $card = IdCard::query()->with('employee.currentAssignment')->where('employee_id', $found->id)->where('is_current', true)->latest('created_at')->first();

        return response()->json(['data' => [
            'employee_id' => $found->id,
            'employee_number' => $found->employee_number,
            'status' => $found->status,
            'employed' => $found->status === Employee::ACTIVE,
            'assignment_active' => $found->currentAssignment?->isEffective() ?? false,
            'card_valid' => $card?->isValidCredential() ?? false,
            'card_code' => $card === null ? 'CARD_NOT_FOUND' : $card->invalidReason(),
        ]]);
    }

    /**
     * The service check: is this card valid now? A machine-readable code says
     * why not. The holder is named only to a client that may read employees.
     */
    public function verifyCard(Request $request): JsonResponse
    {
        // By the card's UUID, or by the token its QR carries (ADR 0010); a credential denial names its code.
        $data = $request->validate(['card_uuid' => ['required_without:token', 'uuid'], 'token' => ['required_without:card_uuid', 'string', 'max:128']]);
        $client = $this->client($request);
        if (isset($data['token'])) {
            ['card' => $card, 'code' => $code] = app(CredentialVerifier::class)->check($data['token']);
        } else {
            $card = IdCard::query()->with('employee.currentAssignment')->where('public_card_uuid', $data['card_uuid'])->first();
            $code = $card?->invalidReason();
        }
        if ($card === null || ! $this->scope->allows($client, $card->employee?->currentAssignment?->organization_id)) {
            return response()->json(['valid' => false, 'code' => $card === null && $code !== null && $code !== 'CREDENTIAL_UNKNOWN' ? $code : 'CARD_NOT_FOUND', 'message' => 'Card verification failed.'], 404);
        }

        return response()->json([
            'valid' => $code === null,
            'code' => $code,
            'status' => $card->status,
            'expires_at' => $card->expires_at?->toIso8601String(),
            'holder' => $client->hasScope('employees:basic-read') ? [
                'employee_id' => $card->employee?->id,
                'employee_number' => $card->employee?->employee_number,
                'full_name' => $card->employee?->full_name,
                'organization_id' => $card->employee?->currentAssignment?->organization_id,
            ] : null,
        ]);
    }

    /** @return Builder<Employee> employees placed inside the client's organizations */
    private function employeeQuery(Request $request): Builder
    {
        $ids = $this->scope->organizationIds($this->client($request));

        // Approved employees only: drafts and records under verification are not authoritative yet (ADR 0008).
        return Employee::query()->with('currentAssignment')->whereNotIn('status', Employee::PRE_APPROVAL)
            ->when($ids !== null, fn (Builder $q) => $q->whereHas('currentAssignment', fn (Builder $a) => $a->whereIn('organization_id', $ids)));
    }

    /** @return array{status?: string, organization_id?: string, updated_since?: string, per_page?: int} */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', 'string', 'max:32'],
            'organization_id' => ['sometimes', 'uuid'],
            'updated_since' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string> child id => parent id in the hierarchy in force
     */
    private function parents(array $ids): array
    {
        $version = HierarchyVersion::current();

        return $version === null ? [] : OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
            ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)->whereIn('child_organization_id', $ids)
            ->pluck('parent_organization_id', 'child_organization_id')->all();
    }

    private function client(Request $request): ApiClient
    {
        $client = $request->attributes->get('api_client');
        abort_unless($client instanceof ApiClient, EnsureApiClient::error(403, 'NOT_AN_API_CLIENT', 'This route is for integration clients.'));

        return $client;
    }
}
