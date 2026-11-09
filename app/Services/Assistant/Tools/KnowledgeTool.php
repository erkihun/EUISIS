<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;
use App\Services\Intelligence\Knowledge\KnowledgeAssistant;
use Throwable;

/**
 * `knowledge_search`: the Intelligence Knowledge Assistant, which already
 * filters by permission, classification and scope in SQL before anything
 * reaches the model, and answers with citations or quoted passages.
 */
final class KnowledgeTool
{
    public const KEY = 'knowledge_search';

    public function __construct(private readonly KnowledgeAssistant $knowledge) {}

    public function run(AssistantContext $context, string $question): ToolResult
    {
        $locale = $context->locale;
        if (! $context->can('knowledge')) {
            return ToolResult::denied(self::KEY, __('assistant.answers.denied_knowledge', [], $locale));
        }
        try {
            $result = $this->knowledge->ask($context->user, $question, $locale);
        } catch (Throwable $e) {
            report($e);

            return ToolResult::empty(self::KEY, __('assistant.answers.knowledge_empty', [], $locale));
        }

        if ($result['notice'] === 'disabled') {
            return ToolResult::denied(self::KEY, __('assistant.answers.knowledge_disabled', [], $locale));
        }
        if (! $result['grounded']) {
            return ToolResult::empty(self::KEY, __('assistant.answers.knowledge_empty', [], $locale));
        }

        $blocks = [];
        $lines = [];
        if ($result['mode'] === 'ai' && $result['answer'] !== null) {
            $blocks[] = ['type' => 'text', 'text' => $result['answer']];
            $lines[] = $result['answer'];
        } else {
            $blocks[] = ['type' => 'text', 'text' => __('assistant.answers.knowledge_passages', [], $locale)];
            foreach ($result['passages'] as $passage) {
                $heading = $passage['heading'] !== null && $passage['heading'] !== '' ? ' — '.$passage['heading'] : '';
                $blocks[] = ['type' => 'evidence', 'title' => $passage['title'].$heading, 'rows' => [['label' => '', 'value' => mb_substr($passage['content'], 0, 700)]]];
                $lines[] = $passage['title'].': '.mb_substr($passage['content'], 0, 300);
            }
        }
        $titles = array_values(array_unique(array_map(fn (array $c): string => (string) ($c['title'] ?? ''), $result['citations'])));
        if ($titles !== []) {
            $blocks[] = ['type' => 'sources', 'items' => array_map(fn (string $t): array => ['title' => $t], array_filter($titles))];
        }

        return new ToolResult(self::KEY, 'ok', $blocks, ['mode' => $result['mode'], 'confidence' => $result['confidence'], 'run_id' => $result['run_id'], 'ai' => $result['mode'] === 'ai'], $lines);
    }
}
