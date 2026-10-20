<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\AccessRequestStatus;
use App\Enums\Communication\AnnouncementStatus;
use App\Enums\Communication\ReportStatus;
use App\Models\Communication\Announcement;
use App\Models\Communication\CommunicationAccessRequest;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational figures for the people who run the module: volumes, the
 * realtime pipeline, and work waiting on someone. Counts only, never content
 * or names.
 */
final class CommunicationMetrics
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $since = now()->subDay();
        $driver = (string) config('broadcasting.default');
        $queue = config('communication.broadcast_queue');

        return [
            'messages_24h' => Message::query()->where('created_at', '>=', $since)->count(),
            'active_conversations_24h' => Conversation::query()->where('last_message_at', '>=', $since)->count(),
            'live_announcements' => Announcement::query()->where('status', AnnouncementStatus::Published->value)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'open_reports' => CommunicationReport::query()->whereIn('status', [ReportStatus::Open->value, ReportStatus::UnderReview->value])->count(),
            'pending_access_requests' => CommunicationAccessRequest::query()->where('status', AccessRequestStatus::Requested->value)->count(),
            'active_access_grants' => CommunicationAccessRequest::query()->whereIn('status', [AccessRequestStatus::Approved->value, AccessRequestStatus::Active->value])
                ->where('expires_at', '>', now())->count(),
            'realtime' => [
                'driver' => $driver,
                'enabled' => ! in_array($driver, ['', 'null', 'log'], true),
                'queue' => $queue ?: (string) config('queue.connections.'.config('queue.default').'.queue', 'default'),
                'waiting' => $this->waitingBroadcasts($queue ?: null),
                'failures' => (int) Cache::get(CommunicationBroadcaster::FAILURE_METRIC, 0),
            ],
            'messages_sent_total' => (int) Cache::get('communication:metrics:messages_sent', 0),
            'attachment_rejections_total' => (int) Cache::get('communication:metrics:attachment_rejections', 0),
        ];
    }

    /** Broadcast jobs waiting on the database queue (null when another queue driver is used). */
    private function waitingBroadcasts(?string $queue): ?int
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return null;
        }

        return DB::table('jobs')
            ->when($queue, fn ($q) => $q->where('queue', $queue))
            ->where('payload', 'like', '%BroadcastEvent%')->count();
    }
}
