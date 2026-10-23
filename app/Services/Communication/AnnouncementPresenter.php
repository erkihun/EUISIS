<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\AnnouncementStatus;
use App\Enums\Communication\AnnouncementTargetType;
use App\Models\Communication\Announcement;
use App\Models\Communication\AnnouncementAttachment;
use App\Models\Communication\AnnouncementReceipt;
use App\Models\Communication\Conversation;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\RichText\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** How announcements look to readers (feed, page) and to the people who manage them. */
final class AnnouncementPresenter
{
    public const PAGE = 20;

    public function __construct(
        private readonly AnnouncementAudience $audience,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function feed(User $user, string $filter, ?string $cursor, string $locale): array
    {
        $query = $this->audience->liveFor($user)->withCount('attachments');
        $mine = fn () => AnnouncementReceipt::query()->select('announcement_id')->where('user_id', $user->getKey());
        if ($filter === 'unread') {
            $query->whereNotIn('announcements.id', $mine()->whereNotNull('viewed_at'));
        } elseif ($filter === 'acknowledge') {
            $query->where('acknowledgement_required', true)->whereNotIn('announcements.id', $mine()->whereNotNull('acknowledged_at'));
        }

        return $this->page($query, 'published_at', $cursor, function ($rows) use ($user, $locale): array {
            $receipts = AnnouncementReceipt::query()->where('user_id', $user->getKey())->whereIn('announcement_id', $rows->modelKeys())->get()->keyBy('announcement_id');
            // Listing it in someone's feed is delivery.
            AnnouncementReceipt::query()->where('user_id', $user->getKey())->whereIn('announcement_id', $rows->modelKeys())->whereNull('delivered_at')->update(['delivered_at' => now()]);

            return $rows->map(fn (Announcement $a) => $this->summary($a, $locale, $receipts->get($a->getKey())))->all();
        });
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function managed(User $user, ?string $status, ?string $cursor, string $locale): array
    {
        // Archived by the retention policy: kept for the record, out of the working list.
        $query = $this->manageableBy(Announcement::query(), $user)->whereNull('archived_at')->withCount('attachments');
        if ($status !== null && AnnouncementStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return $this->page($query, 'updated_at', $cursor, fn ($rows) => $rows->map(fn (Announcement $a) => [
            ...$this->summary($a, $locale, null),
            'target_count' => $a->target_count,
            'acknowledged_count' => $a->acknowledgement_required ? AnnouncementReceipt::query()->where('announcement_id', $a->getKey())->whereNotNull('acknowledged_at')->count() : null,
            'publish_at' => $a->publish_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ])->all());
    }

    /** Announcements this person wrote, or that sit in their scope for editing, publishing or cancelling. */
    public function manageableBy(Builder $query, User $user): Builder
    {
        $managesAny = $user->can('announcements.edit') || $user->can('announcements.publish') || $user->can('announcements.cancel');

        return $query->where(function (Builder $w) use ($user, $managesAny): void {
            $w->where('created_by', $user->getKey());
            if ($managesAny) {
                $this->scope->isUnrestricted($user)
                    ? $w->orWhereRaw('1 = 1')
                    : $w->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user));
            }
        });
    }

    /** @return array<string, mixed> */
    public function summary(Announcement $a, string $locale, ?AnnouncementReceipt $receipt): array
    {
        return [
            'id' => $a->getKey(),
            'title' => $a->localizedTitle($locale),
            'excerpt' => Str::limit(trim(preg_replace('/\s+/u', ' ', RichText::plainText($a->localizedBody($locale))) ?? ''), 180),
            'priority' => $a->priority->value,
            'status' => $a->status->value,
            'published_at' => $a->published_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'acknowledgement_required' => $a->acknowledgement_required,
            'has_attachments' => ($a->attachments_count ?? 0) > 0,
            'viewed' => $receipt?->viewed_at !== null,
            'acknowledged' => $receipt?->acknowledged_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Announcement $a, User $viewer, string $locale): array
    {
        $receipt = AnnouncementReceipt::query()->where('announcement_id', $a->getKey())->where('user_id', $viewer->getKey())->first();
        $gate = Gate::forUser($viewer);
        $manage = $gate->allows('manage', $a);

        return [
            ...$this->summary($a, $locale, $receipt),
            'body' => $a->localizedBody($locale),
            'acknowledged_at' => $receipt?->acknowledged_at?->toIso8601String(),
            'publisher' => $a->publisher?->name,
            'attachments' => $a->attachments()->orderBy('created_at')->get()->map(fn (AnnouncementAttachment $f) => [
                'id' => $f->getKey(), 'name' => $f->original_name, 'size' => $f->size, 'extension' => $f->extension,
                'downloadable' => $f->scan_status->isDownloadable(),
            ])->values(),
            'manage' => $manage ? [
                'title' => $a->title, 'title_am' => $a->title_am, 'body' => $a->body, 'body_am' => $a->body_am,
                'publish_at' => $a->publish_at?->toIso8601String(),
                'cancelled_at' => $a->cancelled_at?->toIso8601String(),
                'cancel_reason' => $a->cancel_reason,
                'author' => $a->creator?->name,
                'targets' => $this->targetLabels($a, $locale),
                'stats' => $this->stats($a),
            ] : null,
            'can' => [
                'acknowledge' => $a->acknowledgement_required && $receipt?->acknowledged_at === null && $gate->allows('acknowledge', $a),
                'edit' => $gate->allows('update', $a),
                'publish' => $gate->allows('publish', $a),
                'cancel' => $gate->allows('cancel', $a),
                'export' => $manage && $gate->allows('exportAcknowledgements', $a),
            ],
        ];
    }

    /** @return array{targeted: ?int, delivered: int, viewed: int, acknowledged: int} */
    public function stats(Announcement $a): array
    {
        $row = AnnouncementReceipt::query()->where('announcement_id', $a->getKey())
            ->selectRaw('count(delivered_at) as delivered, count(viewed_at) as viewed, count(acknowledged_at) as acknowledged')->first();

        return [
            'targeted' => $a->target_count,
            'delivered' => (int) ($row->delivered ?? 0),
            'viewed' => (int) ($row->viewed ?? 0),
            'acknowledged' => (int) ($row->acknowledged ?? 0),
        ];
    }

    /** @return list<array{type: string, id: ?string, label: string}> */
    public function targetLabels(Announcement $a, string $locale): array
    {
        $name = fn ($row) => $row === null ? '—' : ($locale === 'am' && filled($row->name_am ?? null) ? $row->name_am : ($row->name_en ?? $row->name ?? '—'));

        return $a->targets()->get(['target_type', 'target_id'])->map(function ($t) use ($name, $locale): array {
            $type = $t->target_type instanceof AnnouncementTargetType ? $t->target_type : AnnouncementTargetType::tryFrom((string) $t->target_type);
            $label = match ($type) {
                AnnouncementTargetType::City => __('communication.targets.city', [], $locale),
                AnnouncementTargetType::Organization, AnnouncementTargetType::OrganizationSubtree => $name(Organization::query()->find($t->target_id, ['id', 'name_en', 'name_am'])),
                AnnouncementTargetType::OrganizationUnit => $name(OrganizationUnit::query()->find($t->target_id, ['id', 'name_en', 'name_am'])),
                AnnouncementTargetType::Employee => (string) (Employee::query()->whereKey($t->target_id)->value($locale === 'am' ? 'full_name' : 'name_en')
                    ?? Employee::query()->whereKey($t->target_id)->value('full_name') ?? '—'),
                AnnouncementTargetType::Role => (string) (Role::query()->whereKey((int) $t->target_id)->value('name') ?? '—'),
                AnnouncementTargetType::Group => (string) (Conversation::query()->whereKey($t->target_id)->value('title') ?? '—'),
                default => '—',
            };

            return ['type' => $type?->value ?? (string) $t->target_type, 'id' => $t->target_id, 'label' => $label];
        })->values()->all();
    }

    /**
     * @param  callable(Collection<int, Announcement>): list<array<string, mixed>>  $present
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function page(Builder $query, string $column, ?string $cursor, callable $present): array
    {
        if ($cursor !== null && ($decoded = json_decode((string) base64_decode($cursor, true), true)) && count($decoded) === 2) {
            [$at, $id] = $decoded;
            $query->where(fn ($q) => $q->where("announcements.{$column}", '<', $at)
                ->orWhere(fn ($e) => $e->where("announcements.{$column}", $at)->where('announcements.id', '<', $id)));
        }
        $rows = $query->orderByDesc("announcements.{$column}")->orderByDesc('announcements.id')->limit(self::PAGE + 1)->get();
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);
        $last = $rows->last();

        return [
            'data' => $present($rows),
            'next_cursor' => $more && $last ? base64_encode((string) json_encode([$last->{$column}?->toDateTimeString(), $last->getKey()])) : null,
        ];
    }
}
