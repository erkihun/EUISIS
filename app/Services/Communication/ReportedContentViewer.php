<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\ConversationType;
use App\Enums\Communication\ReportStatus;
use App\Models\Communication\CommunicationReport;
use App\Models\Communication\Message;
use App\Models\User;

/**
 * What a moderator may see of a report: the reported message and a few
 * messages either side of it, never the rest of a private conversation. For a
 * reported group or channel, its latest few messages. Every opening is audited
 * and moves an open report to "under review".
 */
final class ReportedContentViewer
{
    public const CONTEXT = 3;

    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @return array<string, mixed> */
    public function open(User $moderator, CommunicationReport $report, string $locale): array
    {
        $conversation = $report->conversation;
        $context = collect();
        if ($report->message_id !== null) {
            $before = Message::query()->withTrashed()->where('conversation_id', $report->conversation_id)->where('id', '<', $report->message_id)
                ->orderByDesc('id')->limit(self::CONTEXT)->get()->reverse();
            $target = Message::query()->withTrashed()->whereKey($report->message_id)->get();
            $after = Message::query()->withTrashed()->where('conversation_id', $report->conversation_id)->where('id', '>', $report->message_id)
                ->orderBy('id')->limit(self::CONTEXT)->get();
            $context = $before->concat($target)->concat($after)->values();
        } elseif ($conversation !== null) {
            $context = Message::query()->withTrashed()->where('conversation_id', $conversation->getKey())->orderByDesc('id')->limit(self::CONTEXT * 2 + 1)->get()->reverse()->values();
        }
        $context->load(['sender:id,name', 'attachments']);

        if ($report->status === ReportStatus::Open) {
            $report->forceFill(['status' => ReportStatus::UnderReview])->save();
        }
        $this->audit->execute(AuditEventType::CommunicationReportedContentAccessed, $moderator, $report, $conversation?->organization_id, null,
            ['message_ids' => $context->modelKeys(), 'conversation_id' => $report->conversation_id]);

        return [
            'conversation' => $conversation === null ? null : [
                'type' => $conversation->type->value,
                // A direct conversation is described, never named after its two people.
                'title' => $conversation->type === ConversationType::Direct ? null : ($conversation->channel?->displayName($locale) ?? $conversation->title),
            ],
            'messages' => $context->map(fn (Message $m) => [
                'id' => $m->getKey(),
                'reported' => $m->getKey() === $report->message_id,
                'sender' => $m->sender?->name,
                'sender_id' => $m->sender_id,
                'type' => $m->type->value,
                'body' => $m->body,
                'deleted' => $m->trashed(),
                'attachments' => $m->attachments->map(fn ($a) => ['name' => $a->original_name, 'size' => $a->size])->values(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
