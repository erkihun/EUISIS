<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\ChannelType;
use App\Models\Communication\Channel;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The channels a person can see in the channel browser: the ones they are in,
 * and the organization channels they may join (placed in the channel's scope).
 * Private, project and system-managed channels are listed only to members.
 */
final class ChannelCatalog
{
    public const PAGE = 30;

    public function __construct(
        private readonly CommunicationHierarchy $hierarchy,
    ) {}

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function page(User $user, string $filter, ?string $search, ?string $cursor, string $locale): array
    {
        $memberOf = ConversationMember::query()->select('conversation_id')->where('user_id', $user->getKey())->whereNull('left_at');
        $query = Channel::query()->whereNull('channels.archived_at')->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am']);

        if ($filter === 'mine') {
            $query->whereIn('conversation_id', $memberOf);
        } else {
            $query->where(fn (Builder $q) => $q->whereIn('conversation_id', $memberOf)
                ->orWhere(fn (Builder $j) => $this->joinableBy($j, $user)));
        }
        if ($search !== null && mb_strlen(trim($search)) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';
            $query->where(fn ($q) => $q->where('channels.name', ci_like_operator(), $like)->orWhere('channels.name_am', ci_like_operator(), $like));
        }
        if ($cursor !== null && ($decoded = json_decode((string) base64_decode($cursor, true), true)) && count($decoded) === 2) {
            [$name, $id] = $decoded;
            $query->where(fn ($q) => $q->where('channels.name', '>', $name)->orWhere(fn ($e) => $e->where('channels.name', $name)->where('channels.id', '>', $id)));
        }

        $rows = $query->orderBy('channels.name')->orderBy('channels.id')->limit(self::PAGE + 1)->get();
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);
        $mine = ConversationMember::query()->where('user_id', $user->getKey())->whereNull('left_at')
            ->whereIn('conversation_id', $rows->pluck('conversation_id'))->pluck('role', 'conversation_id');
        $last = $rows->last();
        $counts = ConversationMember::query()->whereIn('conversation_id', $rows->pluck('conversation_id'))->whereNull('left_at')
            ->selectRaw('conversation_id, count(*) as n')->groupBy('conversation_id')->pluck('n', 'conversation_id');

        return [
            'data' => $rows->map(fn (Channel $c): array => [
                'id' => $c->getKey(),
                'conversation_id' => $c->conversation_id,
                'name' => $c->displayName($locale),
                'description' => $c->description,
                'type' => $c->type->value,
                'organization' => $c->organization ? ($locale === 'am' && $c->organization->name_am ? $c->organization->name_am : $c->organization->name_en) : null,
                'unit' => $c->organizationUnit ? ($locale === 'am' && $c->organizationUnit->name_am ? $c->organizationUnit->name_am : $c->organizationUnit->name_en) : null,
                'member_count' => (int) ($counts[$c->conversation_id] ?? 0),
                'auto_membership' => $c->auto_membership,
                'posting_policy' => $c->posting_policy->value,
                'joined' => $mine->has($c->conversation_id),
                'can_join' => ! $mine->has($c->conversation_id) && Gate::forUser($user)->allows('join', $c),
            ])->values()->all(),
            'next_cursor' => $more && $last ? base64_encode((string) json_encode([$last->name, $last->getKey()])) : null,
        ];
    }

    /** Organization channels whose scope covers the person's current placement. */
    private function joinableBy(Builder $query, User $user): void
    {
        $assignment = $this->hierarchy->assignmentOf($user);
        $query->where('channels.type', ChannelType::PublicOrganization->value);
        if ($assignment === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        $org = (string) $assignment->organization_id;
        $unit = (string) $assignment->organization_unit_id;
        $orgUp = $this->hierarchy->organizationAncestors($org);
        $unitUp = $unit !== '' ? $this->hierarchy->unitAncestors($unit) : [];

        $query->where(fn (Builder $q) => $q
            ->where(fn ($city) => $city->whereNull('channels.organization_id')->whereNull('channels.organization_unit_id'))
            ->orWhere(fn ($u) => $u->whereNotNull('channels.organization_unit_id')->where(fn ($w) => $w
                ->where('channels.organization_unit_id', $unit)
                ->orWhere(fn ($d) => $d->where('channels.include_descendants', true)->whereIn('channels.organization_unit_id', $unitUp))))
            ->orWhere(fn ($o) => $o->whereNull('channels.organization_unit_id')->whereNotNull('channels.organization_id')->where(fn ($w) => $w
                ->where('channels.organization_id', $org)
                ->orWhere(fn ($d) => $d->where('channels.include_descendants', true)->whereIn('channels.organization_id', $orgUp)))));
    }
}
