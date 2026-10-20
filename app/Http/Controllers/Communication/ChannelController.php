<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\CreateChannelAction;
use App\Actions\Communication\JoinChannelAction;
use App\Enums\Communication\ChannelPostingPolicy;
use App\Enums\Communication\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreChannelRequest;
use App\Models\Communication\Channel;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Services\Communication\ChannelCatalog;
use App\Services\Communication\CommunicationSettings;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The channel browser, creating channels and joining organization channels. */
class ChannelController extends Controller
{
    public function __construct(
        private readonly ChannelCatalog $catalog,
        private readonly CommunicationSettings $settings,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('viewAny', Channel::class), 403);
        $manages = $user->can('channels.create');

        return Inertia::render('Communication/Channels', [
            'initial' => $this->catalog->page($user, 'all', null, null, app()->getLocale()),
            'can' => [
                'createManaged' => $manages,
                'createSystem' => $manages && $user->can('channels.manage'),
                'createOwn' => $this->settings->allowEmployeeChannels(),
            ],
            'types' => ChannelType::values(),
            'postingPolicies' => ChannelPostingPolicy::values(),
        ]);
    }

    public function browse(Request $request): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('viewAny', Channel::class), 403);
        $filter = $request->query('filter') === 'mine' ? 'mine' : 'all';

        return response()->json($this->catalog->page($request->user(), $filter, $request->string('q')->toString() ?: null,
            $request->string('cursor')->toString() ?: null, app()->getLocale()));
    }

    public function store(StoreChannelRequest $request, CreateChannelAction $create): JsonResponse
    {
        $channel = $create->execute($request->user(), $request->validated());

        return response()->json(['id' => $channel->getKey(), 'conversation_id' => $channel->conversation_id], 201);
    }

    public function join(Request $request, Channel $channel, JoinChannelAction $join): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->any(['view', 'join'], $channel), 404);
        $join->execute($request->user(), $channel);

        return response()->json(['conversation_id' => $channel->conversation_id]);
    }

    /** Organizations a channel manager may bind a channel to (search, never a full list). */
    public function organizations(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('channels.create'), 403);
        $term = trim($request->string('q')->toString());
        $locale = app()->getLocale();
        $query = Organization::query()->select(['id', 'name_en', 'name_am', 'code'])->orderBy('name_en')->limit(20);
        $this->scope->applyOrganizationScope($query, $user, 'id');
        if (mb_strlen($term) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('name_en', ci_like_operator(), $like)->orWhere('name_am', ci_like_operator(), $like)->orWhere('code', ci_like_operator(), $like));
        }

        return response()->json(['data' => $query->get()->map(fn (Organization $o) => [
            'id' => $o->getKey(), 'name' => $locale === 'am' && $o->name_am ? $o->name_am : $o->name_en, 'code' => $o->code,
        ])->values()]);
    }

    /** Units of one organization the manager may use. */
    public function units(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->string('organization_id')->toString();
        abort_unless($user->can('channels.create') && $organizationId !== '' && $this->scope->canAccessOrganization($user, $organizationId), 403);
        $term = trim($request->string('q')->toString());
        $locale = app()->getLocale();
        $query = OrganizationUnit::query()->where('organization_id', $organizationId)->select(['id', 'name_en', 'name_am', 'code'])->orderBy('name_en')->limit(30);
        if (mb_strlen($term) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('name_en', ci_like_operator(), $like)->orWhere('name_am', ci_like_operator(), $like));
        }

        return response()->json(['data' => $query->get()->map(fn (OrganizationUnit $u) => [
            'id' => $u->getKey(), 'name' => $locale === 'am' && $u->name_am ? $u->name_am : $u->name_en, 'code' => $u->code,
        ])->values()]);
    }
}
