<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\AssistantPageRegistry;
use App\Services\Intelligence\Knowledge\KnowledgeRetriever;
use Throwable;

/**
 * `page_help`: the registry's approved guidance for the current module
 * (purpose, steps, grants, mistakes) plus, when the knowledge capability is
 * on, the best approved passages for the module and question. Needs nothing
 * beyond being signed in; unknown pages get general guidance.
 */
final class PageHelpTool
{
    public const KEY = 'page_help';

    private const PASSAGES = 3;

    public function __construct(private readonly KnowledgeRetriever $retriever) {}

    public function run(AssistantContext $context, string $question): ToolResult
    {
        $locale = $context->locale;
        $module = $context->page['module'];
        $known = AssistantPageRegistry::hasGuidance($module);
        $guide = __('assistant.pages.'.($known ? $module : AssistantPageRegistry::GENERAL), [], $locale);
        $guide = is_array($guide) ? $guide : [];
        $label = $context->page['module_label'];

        $blocks = [];
        $lines = [];
        if (! $known) {
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => __('assistant.answers.unknown_page', [], $locale)];
        } else {
            $lines[] = __('assistant.answers.help_intro', ['module' => $label], $locale);
        }
        if ($context->page['record'] !== null) {
            $record = $context->page['record'];
            $rows = array_filter($record['summary'], fn ($v): bool => $v !== null && $v !== '');
            $blocks[] = ['type' => 'evidence', 'title' => __('assistant.answers.record_help', ['label' => $record['label']], $locale), 'rows' => array_map(fn (string $k, string $v): array => ['label' => ucfirst($k), 'value' => $v], array_keys($rows), array_values($rows))];
        }
        if (! empty($guide['purpose'])) {
            $blocks[] = ['type' => 'text', 'text' => '**'.__('assistant.answers.help_purpose', [], $locale).'** '.$guide['purpose']];
            $lines[] = $guide['purpose'];
        }
        if (! empty($guide['steps'])) {
            $blocks[] = ['type' => 'text', 'text' => '**'.__('assistant.answers.help_steps', [], $locale).'**'];
            $blocks[] = ['type' => 'steps', 'items' => array_values($guide['steps'])];
        }
        if (! empty($guide['permissions'])) {
            $blocks[] = ['type' => 'text', 'text' => '**'.__('assistant.answers.help_permissions', [], $locale).'** '.$guide['permissions']];
        }
        if (! empty($guide['mistakes'])) {
            $blocks[] = ['type' => 'text', 'text' => '**'.__('assistant.answers.help_mistakes', [], $locale).'**'];
            $blocks[] = ['type' => 'bullets', 'items' => array_values($guide['mistakes'])];
        }

        $sources = [];
        $passages = [];
        if ($context->can('knowledge')) {
            try {
                $chunks = $this->retriever->search($context->user, trim($label.' '.$question), self::PASSAGES);
            } catch (Throwable) {
                $chunks = [];
            }
            foreach ($chunks as $chunk) {
                $sources[$chunk['title']] = true;
                $passages[] = ['title' => $chunk['title'], 'heading' => $chunk['heading'], 'content' => mb_substr($chunk['content'], 0, 600)];
            }
            if ($sources !== []) {
                $blocks[] = ['type' => 'sources', 'items' => array_map(fn (string $t): array => ['title' => $t], array_keys($sources))];
            }
        }

        return new ToolResult(self::KEY, 'ok', $blocks, [
            'module' => $label, 'known_page' => $known, 'purpose' => $guide['purpose'] ?? null, 'steps' => $guide['steps'] ?? [],
            'permissions' => $guide['permissions'] ?? null, 'mistakes' => $guide['mistakes'] ?? [], 'record' => $context->page['record'] === null ? null : ['label' => $context->page['record']['label'], ...$context->page['record']['summary']],
            'passages' => $passages,
        ], $lines, $passages === [] ? null : 'knowledge_documents');
    }
}
