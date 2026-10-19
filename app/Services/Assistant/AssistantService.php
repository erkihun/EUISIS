<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Assistant\Tools\BusinessMetricTool;
use App\Services\Assistant\Tools\KnowledgeTool;
use App\Services\Assistant\Tools\PageHelpTool;
use App\Services\Assistant\Tools\SupportTool;
use App\Services\Assistant\Tools\SystemHealthTool;
use App\Services\Assistant\Tools\ToolResult;
use App\Services\Intelligence\IntelligenceArea;
use Throwable;

/**
 * One turn: store the question, route it, run the allowed tools, compose
 * the answer, store it. The orchestration is deliberately small; every
 * decision about WHAT and WHERE lives in the context and the tools
 * (docs/assistant/architecture.md).
 */
final class AssistantService
{
    public function __construct(
        private readonly AssistantIntentRouter $router,
        private readonly AssistantConversations $conversations,
        private readonly AssistantAnswerComposer $composer,
        private readonly PageHelpTool $pageHelp,
        private readonly KnowledgeTool $knowledge,
        private readonly BusinessMetricTool $metrics,
        private readonly SystemHealthTool $health,
        private readonly SupportTool $support,
    ) {}

    /** @return list<array{key: string, label: string}> */
    public function quickActions(AssistantContext $context): array
    {
        $keys = ['explain_page', 'how_to'];
        if ($context->can('knowledge')) {
            $keys[] = 'find';
            $keys[] = 'ask_module';
        }
        if ($context->can('diagnostics')) {
            $keys[] = 'system_status';
        }
        if ($context->can('support')) {
            $keys[] = 'report_problem';
            $keys[] = 'create_ticket';
        }

        return array_map(fn (string $key): array => ['key' => $key, 'label' => (string) __('assistant.quick_actions.'.$key, [], $context->locale)], $keys);
    }

    /** @return array{conversation: AssistantConversation, user: AssistantMessage, reply: AssistantMessage} */
    public function answer(AssistantContext $context, ?AssistantConversation $conversation, string $message, ?string $action): array
    {
        $started = microtime(true);
        $conversation ??= $this->conversations->activeOrStart($context->user, $context->page['module'], $context->page['route']);
        $history = $this->conversations->window($conversation);
        $userMessage = $this->conversations->append($conversation, 'user', $message, [], ['mode' => null]);

        $route = $this->router->route($action, $message);
        $mode = $route['mode'];
        $results = [];
        $narrate = true;

        try {
            switch ($mode) {
                case AssistantIntentRouter::HELP:
                    $results[] = $this->pageHelp->run($context, $message);
                    break;
                case AssistantIntentRouter::KNOWLEDGE:
                    $results[] = $context->can('knowledge') ? $this->knowledge->run($context, $message) : $this->pageHelp->run($context, $message);
                    break;
                case AssistantIntentRouter::BUSINESS_ANALYSIS:
                    if (! $context->can('analytics')) {
                        $results[] = ToolResult::denied(BusinessMetricTool::KEY, __('assistant.answers.denied_analytics', [], $context->locale));
                        $results[] = $this->pageHelp->run($context, $message);
                    } else {
                        $results[] = $this->metrics->run($context, $route['area'] ?? IntelligenceArea::Executive, $route['metrics'], $route['period']);
                    }
                    break;
                case AssistantIntentRouter::SYSTEM_DIAGNOSTICS:
                    $results[] = $this->health->status($context);
                    $narrate = $context->can('diagnostics');
                    break;
                case AssistantIntentRouter::SUPPORT:
                    $lookup = $this->health->lookup($context, $route['error_reference'] === null ? null : null);
                    $results[] = $lookup;
                    $incidentReference = $lookup->data['incidents'][0]['reference'] ?? null;
                    $results[] = $this->support->run($context, $action === 'report_problem' || $action === 'create_ticket' ? '' : $message, $route['error_reference'], is_string($incidentReference) ? $incidentReference : null, (bool) ($lookup->data['page_incident'] ?? false));
                    $narrate = false;
                    break;
            }
        } catch (Throwable $e) {
            report($e);
            $results = [ToolResult::empty('error', __('assistant.answers.knowledge_empty', [], $context->locale))];
        }

        $composed = $this->composer->compose($context, $mode, $message, $results, $history, $narrate);
        if ($composed['ai'] && $mode !== AssistantIntentRouter::SUPPORT) {
            $composed['blocks'][] = ['type' => 'disclaimer', 'text' => __('assistant.disclaimer', [], $context->locale)];
        } elseif ($composed['status'] === 'fallback') {
            $composed['blocks'][] = ['type' => 'notice', 'tone' => 'info', 'text' => __('assistant.answers.ai_fallback', [], $context->locale)];
        }

        $reply = $this->conversations->append($conversation, 'assistant', $composed['text'], $composed['blocks'], [
            'mode' => $mode, 'tools' => array_values(array_unique(array_map(fn (ToolResult $r): string => $r->tool, $results))),
            'status' => $composed['status'], 'ai' => $composed['ai'], 'run_id' => $composed['run_id'], 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return ['conversation' => $conversation->refresh(), 'user' => $userMessage, 'reply' => $reply];
    }
}
