<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Communication\Domain\Enums\AccessRequestStatus;
use Modules\Communication\Domain\Enums\AnnouncementPriority;
use Modules\Communication\Domain\Enums\AnnouncementStatus;

/**
 * Internal communication: the announcements institutions in scope published,
 * how many staff they reached and how many read or acknowledged them, and the
 * volume of messaging. Counts only; no message, author or reader is shown.
 */
final class CommunicationAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.communication';

    public const CONVERSATION_TYPES = ['DIRECT', 'GROUP', 'CHANNEL'];

    public function panels(SituationContext $context): array
    {
        return [
            'communication_kpis' => fn (): SituationPanel => $this->kpis($context),
            'announcements_by_priority' => fn (): SituationPanel => $this->byPriority($context),
            'conversations_by_type' => fn (): SituationPanel => $this->conversationsByType($context),
            'messages_trend' => fn (): SituationPanel => $this->messagesTrend($context),
            'announcements_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
        ];
    }

    private function announcements(SituationContext $context): Builder
    {
        $query = DB::table('announcements as a');
        $context->scope->apply($query, 'a.organization_id');

        return $query;
    }

    private function publishedInPeriod(SituationContext $context): Builder
    {
        return $this->inPeriod($this->announcements($context), 'a.published_at', $context->period());
    }

    private function conversations(SituationContext $context): Builder
    {
        $query = DB::table('conversations as c');
        $context->scope->apply($query, 'c.organization_id');

        return $query;
    }

    private function messages(SituationContext $context): Builder
    {
        return $this->conversations($context)->join('messages as m', 'm.conversation_id', '=', 'c.id')->whereNull('m.deleted_by');
    }

    private function receipts(SituationContext $context): Builder
    {
        return $this->publishedInPeriod($context)->join('announcement_receipts as r', 'r.announcement_id', '=', 'a.id');
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $delivered = $this->receipts($context)->whereNotNull('r.delivered_at')->count();
        $viewed = $this->receipts($context)->whereNotNull('r.viewed_at')->count();
        $ackRequired = $this->receipts($context)->where('a.acknowledgement_required', true);
        $ackDelivered = (clone $ackRequired)->whereNotNull('r.delivered_at')->count();
        $acknowledged = (clone $ackRequired)->whereNotNull('r.acknowledged_at')->count();
        $accessRequests = $this->conversations($context)->join('communication_access_requests as ar', 'ar.conversation_id', '=', 'c.id');

        return SituationPanel::kpis('communication_kpis', $this->title('communication_kpis'), self::SOURCE, [
            $this->kpi('announcements_published', $this->publishedInPeriod($context)->count()),
            $this->kpi('announcements_active', $this->announcements($context)->where('a.status', AnnouncementStatus::Published->value)->where(fn (Builder $q) => $q->whereNull('a.expires_at')->orWhere('a.expires_at', '>', now()))->count()),
            $this->kpi('announcement_reach', (int) $this->publishedInPeriod($context)->sum('a.target_count')),
            $this->kpi('announcement_view_rate', $this->percent($viewed, $delivered), ['unit' => '%']),
            $this->kpi('announcement_ack_rate', $this->percent($acknowledged, $ackDelivered), ['unit' => '%']),
            $this->kpi('messages_sent', $this->inPeriod($this->messages($context), 'm.created_at', $period)->where('m.type', 'TEXT')->count()),
            $this->kpi('conversations_active', $this->inPeriod($this->conversations($context), 'c.last_message_at', $period)->count()),
            $this->kpi('access_requests_pending', $accessRequests->where('ar.status', AccessRequestStatus::Requested->value)->count()),
        ]);
    }

    private function byPriority(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->publishedInPeriod($context), 'a.priority');
        $buckets = array_column(AnnouncementPriority::cases(), 'value');

        return $this->chart('announcements_by_priority', 'donut', self::SOURCE, $this->valueLabels('announcement_priority', $buckets), [
            ['key' => 'announcements', 'label' => SituationLabel::key('situationRoom.series.announcements'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function conversationsByType(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod($this->conversations($context), 'c.last_message_at', $context->period()), 'c.type');

        return $this->chart('conversations_by_type', 'donut', self::SOURCE, $this->valueLabels('conversation_type', self::CONVERSATION_TYPES), [
            ['key' => 'conversations', 'label' => SituationLabel::key('situationRoom.series.conversations'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, self::CONVERSATION_TYPES)],
        ]);
    }

    private function messagesTrend(SituationContext $context): SituationPanel
    {
        $trend = $this->trend($this->messages($context)->where('m.type', 'TEXT'), 'm.created_at', $context->period());

        return $this->chart('messages_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'messages_sent', 'label' => SituationLabel::key('situationRoom.kpi.messages_sent'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $rolled = $this->rolledUp($context, $this->countBy($this->publishedInPeriod($context), 'a.organization_id'));

        return $this->chart('announcements_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'announcements_published', 'label' => SituationLabel::key('situationRoom.kpi.announcements_published'), 'values' => $rolled['values']],
        ], span: 2);
    }
}
