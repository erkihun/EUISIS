<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\AnnouncementPriority;
use App\Enums\Communication\AnnouncementStatus;
use App\Jobs\Communication\ComputeAnnouncementTargetCount;
use App\Models\Communication\Announcement;
use App\Models\Communication\AnnouncementTarget;
use App\Models\User;
use App\Services\Communication\AnnouncementTargeting;
use App\Services\Communication\CommunicationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft, or changes a draft or scheduled announcement. Targets are
 * checked against the author's scope (AnnouncementTargeting); a scheduled
 * announcement keeps its schedule unless a new time is given.
 */
final class SaveAnnouncementAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly AnnouncementTargeting $targeting,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  array{title: string, title_am?: ?string, body: string, body_am?: ?string, priority: string, acknowledgement_required?: bool,
     *     expires_at?: ?string, targets: list<array{type: string, id?: ?string}>}  $data
     */
    public function execute(User $author, array $data, ?Announcement $announcement = null): Announcement
    {
        if (! $this->access->canUse($author)) {
            throw new AuthorizationException;
        }
        if ($announcement !== null) {
            if (! $announcement->status->isEditable()) {
                throw ValidationException::withMessages(['announcement' => __('communication.errors.announcement_not_editable')]);
            }
            if (! Gate::forUser($author)->allows('update', $announcement)) {
                throw new AuthorizationException;
            }
        }
        $resolved = $this->targeting->resolve($author, $data['targets'], $announcement === null ? 'announcements.create' : 'announcements.edit');
        $expires = filled($data['expires_at'] ?? null) ? Carbon::parse((string) $data['expires_at']) : null;
        if ($expires !== null && $expires->isPast()) {
            throw ValidationException::withMessages(['expires_at' => __('communication.errors.expiry_in_past')]);
        }

        $before = $announcement?->only(['title', 'title_am', 'priority', 'acknowledgement_required', 'expires_at']);
        $beforeTargets = $announcement?->targets()->get(['target_type', 'target_id'])->map(fn ($t) => (is_string($t->target_type) ? $t->target_type : $t->target_type->value).':'.$t->target_id)->sort()->values()->all();

        $announcement = DB::transaction(function () use ($author, $data, $resolved, $expires, $announcement): Announcement {
            $model = $announcement ?? (new Announcement)->forceFill(['status' => AnnouncementStatus::Draft, 'created_by' => $author->getKey()]);
            $model->forceFill([
                'title' => trim($data['title']),
                'title_am' => filled($data['title_am'] ?? null) ? trim((string) $data['title_am']) : null,
                'body' => trim($data['body']),
                'body_am' => filled($data['body_am'] ?? null) ? trim((string) $data['body_am']) : null,
                'priority' => AnnouncementPriority::from($data['priority']),
                'acknowledgement_required' => (bool) ($data['acknowledgement_required'] ?? false),
                'expires_at' => $expires,
                'organization_id' => $resolved['organization_id'],
                'target_type' => $resolved['target_type'],
                'target_count' => null,
                'target_count_computed_at' => null,
            ])->save();

            AnnouncementTarget::query()->where('announcement_id', $model->getKey())->delete();
            AnnouncementTarget::query()->insert(array_map(fn (array $t) => [
                'id' => (string) Str::uuid7(), 'announcement_id' => $model->getKey(),
                'target_type' => $t['type'], 'target_id' => $t['id'], 'created_at' => now(),
            ], $resolved['targets']));

            return $model;
        });

        $after = $announcement->only(['title', 'title_am', 'priority', 'acknowledgement_required', 'expires_at']);
        $afterTargets = collect($resolved['targets'])->map(fn ($t) => $t['type'].':'.$t['id'])->sort()->values()->all();
        if ($before === null) {
            $this->audit->execute(AuditEventType::CommunicationAnnouncementCreated, $author, $announcement, $announcement->organization_id,
                null, [...$after, 'targets' => $afterTargets]);
        } else {
            $this->audit->execute(AuditEventType::CommunicationAnnouncementUpdated, $author, $announcement, $announcement->organization_id, $before, $after);
            if ($beforeTargets !== $afterTargets) {
                $this->audit->execute(AuditEventType::CommunicationAnnouncementTargetsChanged, $author, $announcement, $announcement->organization_id,
                    ['targets' => $beforeTargets], ['targets' => $afterTargets]);
            }
        }
        ComputeAnnouncementTargetCount::dispatch($announcement->getKey())->afterCommit();

        return $announcement;
    }
}
