<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Models\Competency;
use App\Models\IscoGroup;
use App\Models\Occupation;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scoped server-side search for the design pickers (brief §46): never the
 * whole catalog in page props. At most LIMIT rows, matched on code or name,
 * inside the organization the viewer may see.
 */
class LookupController extends Controller
{
    public const LIMIT = 30;

    public const KINDS = ['tasks', 'activities', 'processes', 'services', 'positions', 'units', 'occupations', 'competencies', 'isco-groups'];

    /** Any permission that opens a design page may search. */
    private const PERMISSIONS = [
        'institution-mandates.viewAny', 'organization-functions.viewAny', 'service-catalog.viewAny', 'processes.viewAny', 'processes.update',
        'organization-design.viewAny', 'workforce-planning.viewAny', 'position-profiles.viewAny', 'position-profiles.manage', 'job-descriptions.viewAny',
        'competency-catalog.viewAny', 'professions.manage', 'data-mapping.view', 'organization-design-reports.view',
    ];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function __invoke(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $user = $request->user();
        abort_unless(collect(self::PERMISSIONS)->contains(fn ($p) => $user->can($p)), 403);
        $data = $request->validate(['organization_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100']]);
        $organizationId = $data['organization_id'] ?? null;
        $q = trim((string) ($data['q'] ?? ''));
        $like = ci_like_operator();
        $cityLevel = in_array($kind, ['occupations', 'competencies', 'isco-groups'], true);
        if (! $cityLevel) {
            abort_unless($organizationId !== null && $this->scope->canAccess($user, $organizationId), 403);
        }
        $match = fn ($query, array $columns) => $q === '' ? $query : $query->where(function ($w) use ($columns, $like, $q): void {
            foreach ($columns as $column) {
                $w->orWhere($column, $like, "%{$q}%");
            }
        });
        $row = fn ($o, string $code, string $name, ?string $nameAm, array $extra = []) => ['id' => $o->id, 'code' => $code, 'name_en' => $name, 'name_am' => $nameAm, 'label' => trim($code.' · '.$name), ...$extra];

        $rows = match ($kind) {
            'tasks' => $match(ProcessTask::query()->where('organization_id', $organizationId)->where('is_active', true)->with('process:id,code'), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($t) => $row($t, $t->code, $t->name_en, $t->name_am, ['process_code' => $t->process?->code])),
            'activities' => $match(ProcessActivity::query()->where('organization_id', $organizationId)->where('is_active', true)->with('process:id,code'), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($a) => $row($a, $a->code, $a->name_en, $a->name_am, ['process_code' => $a->process?->code])),
            'processes' => $match(Process::query()->where('organization_id', $organizationId)->whereIn('status', ['published', 'draft']), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($p) => $row($p, $p->code, $p->name_en, $p->name_am, ['status' => $p->status->value])),
            'services' => $match(Service::query()->where('organization_id', $organizationId)->whereIn('status', ['published', 'draft']), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($s) => $row($s, $s->code, $s->name_en, $s->name_am, ['status' => $s->status->value])),
            'positions' => $match(Position::query()->where('organization_id', $organizationId)->where('is_active', true), ['job_position_code', 'title_en', 'title_am'])
                ->orderBy('job_position_code')->limit(self::LIMIT)->get()->map(fn ($p) => $row($p, $p->job_position_code, $p->title_en, $p->title_am)),
            'units' => $match(OrganizationUnit::query()->where('organization_id', $organizationId)->where('status', 'active'), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($u) => $row($u, $u->code, $u->name_en, $u->name_am)),
            'occupations' => $match(Occupation::query()->where('is_active', true)->with(['professions' => fn ($p) => $p->where('is_active', true)]), ['code', 'isco_code', 'name_en', 'name_am'])
                ->orderBy('isco_code')->limit(self::LIMIT)->get()->map(fn ($o) => $row($o, $o->isco_code ?? $o->code, $o->name_en ?? $o->code, $o->name_am, ['professions' => $o->professions->map->only(['id', 'code', 'name_en', 'name_am'])->values()->all()])),
            'competencies' => $match(Competency::query()->where('is_active', true)->with('framework:id,code'), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($c) => $row($c, $c->code, $c->name_en, $c->name_am, ['framework_code' => $c->framework?->code])),
            'isco-groups' => $match(IscoGroup::query()->where('is_active', true), ['code', 'name_en', 'name_am'])
                ->orderBy('code')->limit(self::LIMIT)->get()->map(fn ($g) => $row($g, $g->code, $g->name_en, $g->name_am, ['revision' => $g->revision])),
        };

        return response()->json($rows->values()->all());
    }
}
