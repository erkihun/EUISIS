<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;

/**
 * `support_ticket_draft`: the troubleshooting answer (what was checked, two
 * or three things to try) and an offer to open a ticket. Nothing is created
 * here — creation is a separate, explicit request (docs/assistant/support-helpdesk.md).
 */
final class SupportTool
{
    public const KEY = 'support_ticket_draft';

    public function run(AssistantContext $context, string $description, ?string $errorReference, ?string $incidentReference, bool $pageIncident): ToolResult
    {
        $locale = $context->locale;
        $checks = __('assistant.answers.support_checks', [], $locale);
        $blocks = [
            ['type' => 'text', 'text' => __('assistant.answers.support_intro', [], $locale)],
            ['type' => 'steps', 'items' => is_array($checks) ? array_values($checks) : []],
        ];
        $lines = [__('assistant.answers.support_intro', [], $locale)];
        if (! $context->can('support')) {
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => __('assistant.answers.denied_support', [], $locale)];

            return new ToolResult(self::KEY, 'ok', $blocks, ['support' => false], $lines);
        }
        $lines[] = __('assistant.answers.support_offer', [], $locale);
        $blocks[] = [
            'type' => 'ticket_offer',
            'category' => $errorReference !== null || $pageIncident ? 'error' : 'other',
            'description' => mb_substr(trim($description) !== '' ? $description : __('assistant.answers.ticket_offer_description', [], $locale), 0, 2000),
            'error_reference' => $errorReference,
            'incident_reference' => $incidentReference,
        ];

        return new ToolResult(self::KEY, 'ok', $blocks, ['support' => true, 'error_reference' => $errorReference, 'page_incident' => $pageIncident], $lines);
    }
}
