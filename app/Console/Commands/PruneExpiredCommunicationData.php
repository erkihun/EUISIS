<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\AccessRequestStatus;
use App\Enums\Communication\AccessScopeType;
use App\Enums\Communication\AnnouncementStatus;
use App\Enums\Communication\ReportStatus;
use App\Models\Communication\Announcement;
use App\Models\Communication\CommunicationAccessRequest;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\ConversationMember;
use App\Models\Communication\Message;
use App\Models\Communication\MessageAttachment;
use App\Services\Communication\CommunicationSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Applies the Communication retention settings, and only those:
 *
 * - Messages deleted longer ago than "Message retention (days)" are removed
 *   for good, with their files. Live messages are never removed by age.
 * - Expired or withdrawn announcements older than "Announcement retention
 *   (days)" are archived (hidden from lists; kept for the record).
 * - Files uploaded but never sent are removed after a day.
 *
 * Blank retention settings mean no policy: nothing is removed. Anything in a
 * conversation with an open report or an approved, unexpired investigation is
 * on hold and kept. Each run is audited with counts only.
 */
class PruneExpiredCommunicationData extends Command
{
    protected $signature = 'communication:prune {--dry-run : Count what would be removed without removing it}';

    protected $description = 'Apply Communication retention: remove long-deleted messages, archive old announcements, clear abandoned uploads';

    public function handle(CommunicationSettings $settings, WriteAuditLogAction $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['messages' => 0, 'files' => 0, 'announcements' => 0, 'abandoned_uploads' => 0];

        if (($days = $settings->retentionDays()) !== null) {
            $query = Message::onlyTrashed()->where('deleted_at', '<', now()->subDays($days));
            $this->withoutHolds($query);
            $query->orderBy('id')->chunkById(500, function ($messages) use ($dry, &$counts): void {
                $files = MessageAttachment::query()->whereIn('message_id', $messages->modelKeys())->get();
                $counts['messages'] += $messages->count();
                $counts['files'] += $files->count();
                if ($dry) {
                    return;
                }
                foreach ($files as $file) {
                    Storage::disk($file->disk)->delete($file->path);
                }
                MessageAttachment::query()->whereIn('id', $files->modelKeys())->delete();
                // Replies keep pointing nowhere rather than blocking removal.
                Message::withTrashed()->whereIn('parent_message_id', $messages->modelKeys())->update(['parent_message_id' => null]);
                Message::withTrashed()->whereIn('id', $messages->modelKeys())->forceDelete();
            });
        }

        if (($days = $settings->announcementRetentionDays()) !== null) {
            $query = Announcement::query()->whereNull('archived_at')
                ->whereIn('status', [AnnouncementStatus::Expired->value, AnnouncementStatus::Cancelled->value])
                ->where('updated_at', '<', now()->subDays($days));
            $counts['announcements'] = $dry ? $query->count() : $query->update(['archived_at' => now()]);
        }

        $abandoned = MessageAttachment::query()->whereNull('message_id')->where('created_at', '<', now()->subDay())->get();
        $counts['abandoned_uploads'] = $abandoned->count();
        if (! $dry) {
            foreach ($abandoned as $file) {
                Storage::disk($file->disk)->delete($file->path);
            }
            MessageAttachment::query()->whereIn('id', $abandoned->modelKeys())->delete();
        }

        if (! $dry && array_sum($counts) > 0) {
            $audit->execute(AuditEventType::CommunicationRetentionApplied, null, null, null, null, $counts);
        }
        $this->info(($dry ? '[dry run] ' : '').collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }

    /** Leaves out conversations under an open report or an investigation in force. */
    private function withoutHolds(Builder $query): void
    {
        $reported = CommunicationReport::query()->select('conversation_id')->whereNotNull('conversation_id')
            ->whereIn('status', [ReportStatus::Open->value, ReportStatus::UnderReview->value]);
        $grants = CommunicationAccessRequest::query()
            ->whereIn('status', [AccessRequestStatus::Requested->value, AccessRequestStatus::Approved->value, AccessRequestStatus::Active->value])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        $query->whereNotIn('conversation_id', $reported)
            ->whereNotIn('conversation_id', (clone $grants)->where('scope_type', AccessScopeType::Conversation->value)->whereNotNull('conversation_id')->select('conversation_id'))
            ->whereNotIn('conversation_id', ConversationMember::query()->select('conversation_id')
                ->whereIn('user_id', (clone $grants)->where('scope_type', AccessScopeType::Employee->value)->whereNotNull('target_user_id')->select('target_user_id')));
    }
}
