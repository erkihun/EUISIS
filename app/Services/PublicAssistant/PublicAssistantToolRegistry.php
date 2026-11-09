<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use App\Models\IntelligenceIncident;
use App\Services\Intelligence\System\ComponentHealth;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Modules\Feedback\Contracts\PublicOrganizationServices;
use Throwable;

/**
 * The only tools of the public boundary (docs/public-assistant/public-capabilities.md).
 * A fixed table: key → requirement (capability) and the page kinds it may
 * run on. `run()` refuses anything not in the table, whatever asked for it
 * — the router, the browser or the model. No class from the authenticated
 * assistant is referenced here.
 */
final class PublicAssistantToolRegistry
{
    public const ALL_PAGES = ['*'];

    /** @var array<string, array{public: bool, capability: ?string, pages: list<string>, rate: string}> */
    public const TOOLS = [
        'public_page_help' => ['public' => true, 'capability' => null, 'pages' => ['*'], 'rate' => 'normal'],
        'public_knowledge_search' => ['public' => true, 'capability' => 'knowledge', 'pages' => ['*'], 'rate' => 'normal'],
        'public_service_information' => ['public' => true, 'capability' => 'services', 'pages' => ['*'], 'rate' => 'normal'],
        'public_organization_information' => ['public' => true, 'capability' => 'services', 'pages' => ['organization_qr', 'feedback_form'], 'rate' => 'normal'],
        'public_feedback_help' => ['public' => true, 'capability' => null, 'pages' => ['*'], 'rate' => 'normal'],
        'public_tracking_help' => ['public' => true, 'capability' => null, 'pages' => ['*'], 'rate' => 'normal'],
        'public_safe_navigation' => ['public' => true, 'capability' => null, 'pages' => ['*'], 'rate' => 'normal'],
        'public_status' => ['public' => true, 'capability' => 'status', 'pages' => ['*'], 'rate' => 'normal'],
        'public_support_handoff' => ['public' => true, 'capability' => 'support', 'pages' => ['*'], 'rate' => 'normal'],
    ];

    public function __construct(
        private readonly PublicKnowledgeService $knowledge,
        private readonly PublicOrganizationServices $organizationServices,
        private readonly SystemSettingsService $settings,
    ) {}

    public static function exists(string $tool): bool
    {
        return isset(self::TOOLS[$tool]) && self::TOOLS[$tool]['public'] === true;
    }

    public function allowed(string $tool, PublicAssistantContext $context): bool
    {
        if (! self::exists($tool)) {
            return false;
        }
        $meta = self::TOOLS[$tool];
        if ($meta['capability'] !== null && ! $context->can($meta['capability'])) {
            return false;
        }

        return $meta['pages'] === self::ALL_PAGES || in_array($context->kind(), $meta['pages'], true);
    }

    /** Runs one registered tool; a tool that is not allowed in this context returns null (never a degraded answer). */
    public function run(string $tool, PublicAssistantContext $context, string $question = ''): ?PublicToolResult
    {
        if (! $this->allowed($tool, $context)) {
            return null;
        }
        $question = mb_substr(trim($question), 0, 1000);

        return match ($tool) {
            'public_page_help' => $this->pageHelp($context),
            'public_knowledge_search' => $this->knowledgeSearch($context, $question),
            'public_service_information' => $this->serviceInformation($context, $question),
            'public_organization_information' => $this->organizationInformation($context),
            'public_feedback_help' => $this->feedbackHelp($context),
            'public_tracking_help' => $this->trackingHelp($context),
            'public_safe_navigation' => $this->navigation($context),
            'public_status' => $this->status($context),
            'public_support_handoff' => $this->supportHandoff($context),
            default => null,
        };
    }

    private function pageHelp(PublicAssistantContext $context): PublicToolResult
    {
        $locale = $context->locale;
        $kind = $context->kind();
        $guide = __('public_assistant.pages.'.$kind, [], $locale);
        $guide = is_array($guide) ? $guide : (array) __('public_assistant.pages.unknown', [], $locale);
        $blocks = [];
        $lines = [];
        if (! empty($guide['purpose'])) {
            $blocks[] = ['type' => 'text', 'text' => $guide['purpose']];
            $lines[] = $guide['purpose'];
        }
        if (! empty($guide['steps'])) {
            $blocks[] = ['type' => 'steps', 'items' => array_values($guide['steps'])];
            $lines = [...$lines, ...array_values($guide['steps'])];
        }
        if (! empty($guide['notes'])) {
            $blocks[] = ['type' => 'bullets', 'items' => array_values($guide['notes'])];
        }
        $target = $context->page['target'];
        if ($target !== null) {
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => __('public_assistant.answers.target_'.$target['type'], ['name' => $target['name']], $locale)];
        }

        return new PublicToolResult('public_page_help', 'ok', $blocks, ['page' => $kind, 'purpose' => $guide['purpose'] ?? null, 'steps' => $guide['steps'] ?? []], $lines);
    }

    private function knowledgeSearch(PublicAssistantContext $context, string $question): PublicToolResult
    {
        $locale = $context->locale;
        try {
            $faqs = $this->knowledge->searchFaqs($question, $locale);
            $passages = $this->knowledge->searchDocuments($question);
        } catch (Throwable $e) {
            report($e);
            $faqs = [];
            $passages = [];
        }
        if ($faqs === [] && $passages === []) {
            return PublicToolResult::text('public_knowledge_search', __('public_assistant.answers.knowledge_empty', [], $locale), 'empty');
        }
        $blocks = [];
        $lines = [];
        $sources = [];
        foreach ($faqs as $faq) {
            $blocks[] = ['type' => 'text', 'text' => '**'.$faq['question'].'** '.$faq['answer']];
            $lines[] = $faq['question'].' — '.$faq['answer'];
            $sources[__('public_assistant.answers.faq_source', [], $locale)] = PublicAssistantPageRegistry::relative(route('public.support'));
        }
        foreach ($passages as $passage) {
            $blocks[] = ['type' => 'text', 'text' => $passage['content']];
            $lines[] = $passage['content'];
            $sources[$passage['title']] = null;
        }
        $blocks[] = ['type' => 'sources', 'items' => array_map(fn (string $title, ?string $href): array => array_filter(['title' => $title, 'href' => $href], fn ($v) => $v !== null), array_keys($sources), array_values($sources))];

        return new PublicToolResult('public_knowledge_search', 'ok', $blocks, ['faqs' => $faqs, 'passages' => $passages], $lines);
    }

    private function serviceInformation(PublicAssistantContext $context, string $question): PublicToolResult
    {
        $locale = $context->locale;
        try {
            $services = $this->knowledge->searchServices($question, $locale);
        } catch (Throwable $e) {
            report($e);
            $services = [];
        }
        if ($services === []) {
            $blocks = [['type' => 'text', 'text' => __('public_assistant.answers.services_empty', [], $locale)], ['type' => 'actions', 'items' => PublicAssistantPageRegistry::navigation($locale, ['services'])]];

            return new PublicToolResult('public_service_information', 'empty', $blocks, [], [__('public_assistant.answers.services_empty', [], $locale)]);
        }
        $blocks = [['type' => 'text', 'text' => __('public_assistant.answers.services_intro', [], $locale)]];
        $lines = [];
        $actions = [];
        foreach ($services as $i => $service) {
            $text = '**'.$service['name'].'**'.($service['summary'] ? ' '.$service['summary'] : '');
            if ($service['requirements']) {
                $text .= "\n\n".__('public_assistant.answers.requirements', [], $locale).': '.$service['requirements'];
            }
            $blocks[] = ['type' => 'text', 'text' => $text];
            $lines[] = $service['name'].': '.($service['summary'] ?? '');
            $actions[] = ['key' => 'service_'.$i, 'label' => $service['name'], 'href' => $service['href']];
        }
        $blocks[] = ['type' => 'actions', 'items' => $actions];

        return new PublicToolResult('public_service_information', 'ok', $blocks, ['services' => $services], $lines);
    }

    private function organizationInformation(PublicAssistantContext $context): PublicToolResult
    {
        $locale = $context->locale;
        $target = $context->page['target'];
        if ($target === null || $target['type'] !== 'organization') {
            return PublicToolResult::text('public_organization_information', __('public_assistant.answers.no_organization', [], $locale), 'empty');
        }
        $services = ['mode' => 'none', 'items' => []];
        try {
            if ($target['organization_id'] !== null) {
                $services = $this->organizationServices->serviceOptions($target['organization_id'], $locale);
            }
        } catch (Throwable $e) {
            report($e);
        }
        $blocks = [['type' => 'text', 'text' => __('public_assistant.answers.organization_intro', ['name' => $target['name']], $locale)]];
        $lines = [__('public_assistant.answers.organization_intro', ['name' => $target['name']], $locale)];
        $names = array_map(fn (array $s): string => (string) $s['name'], $services['items']);
        if ($names !== []) {
            $blocks[] = ['type' => 'bullets', 'items' => array_slice($names, 0, 12)];
            $lines[] = implode(', ', array_slice($names, 0, 12));
        } elseif ($services['mode'] === 'search') {
            $blocks[] = ['type' => 'text', 'text' => __('public_assistant.answers.organization_services_many', [], $locale)];
        } else {
            $blocks[] = ['type' => 'text', 'text' => __('public_assistant.answers.organization_services_none', [], $locale)];
        }
        if ($target['phone'] !== null) {
            $blocks[] = ['type' => 'contact', 'items' => [['kind' => 'phone', 'label' => $target['phone']['display'], 'href' => $target['phone']['href']]]];
            $lines[] = __('public_assistant.answers.phone', [], $locale).': '.$target['phone']['display'];
        } else {
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => __('public_assistant.answers.contact_unavailable', [], $locale)];
        }
        $blocks[] = ['type' => 'actions', 'items' => $this->targetActions($context)];

        return new PublicToolResult('public_organization_information', 'ok', $blocks, ['organization' => $target['name'], 'services' => array_slice($names, 0, 12), 'phone' => $target['phone']['display'] ?? null], $lines);
    }

    private function feedbackHelp(PublicAssistantContext $context): PublicToolResult
    {
        $locale = $context->locale;
        $type = $context->page['form_type'];
        $keys = $type !== null ? [$type] : ['feedback', 'suggestion', 'complaint'];
        $blocks = [];
        $lines = [];
        foreach ($keys as $key) {
            $guide = __('public_assistant.feedback.'.$key, [], $locale);
            if (! is_array($guide)) {
                continue;
            }
            // Title, meaning and steps in one text block so they stay together on the panel.
            $steps = array_map(fn (string $s): string => '- '.$s, array_values($guide['steps'] ?? []));
            $blocks[] = ['type' => 'text', 'text' => '**'.$guide['title'].'** '.$guide['what'].($steps === [] ? '' : "\n".implode("\n", $steps))];
            $lines[] = $guide['title'].': '.$guide['what'];
        }
        $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => __('public_assistant.feedback.boundary', [], $locale)];
        $lines[] = __('public_assistant.feedback.boundary', [], $locale);
        $actions = $this->targetActions($context);
        if ($actions !== []) {
            $blocks[] = ['type' => 'actions', 'items' => $actions];
        } else {
            $blocks[] = ['type' => 'text', 'text' => __('public_assistant.feedback.scan_hint', [], $locale)];
        }

        return new PublicToolResult('public_feedback_help', 'ok', $blocks, ['types' => $keys], $lines);
    }

    private function trackingHelp(PublicAssistantContext $context): PublicToolResult
    {
        $locale = $context->locale;
        $guide = (array) __('public_assistant.tracking', [], $locale);
        $blocks = [['type' => 'text', 'text' => (string) ($guide['what'] ?? '')], ['type' => 'steps', 'items' => array_values($guide['steps'] ?? [])], ['type' => 'notice', 'tone' => 'info', 'text' => (string) ($guide['no_lookup'] ?? '')]];

        return new PublicToolResult('public_tracking_help', 'ok', $blocks, ['tracking' => $guide['what'] ?? null], [(string) ($guide['what'] ?? ''), (string) ($guide['no_lookup'] ?? '')]);
    }

    private function navigation(PublicAssistantContext $context): PublicToolResult
    {
        $items = [...$this->targetActions($context), ...PublicAssistantPageRegistry::navigation($context->locale)];

        return new PublicToolResult('public_safe_navigation', 'ok', [['type' => 'actions', 'items' => $items]], [], [__('public_assistant.answers.navigation', [], $context->locale)]);
    }

    /** Service Available / Degraded / Temporarily unavailable — a mapping of platform health with no evidence (P-6). */
    private function status(PublicAssistantContext $context): PublicToolResult
    {
        $locale = $context->locale;
        $state = 'available';
        try {
            $states = app(ComponentHealth::class)->states();
            $summary = app(ComponentHealth::class)->summary($states);
            $critical = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->whereIn('severity', ['critical', 'high'])->exists();
            $state = match (true) {
                $summary['unhealthy'] > 0 || $critical => 'unavailable',
                $summary['degraded'] > 0 => 'degraded',
                default => 'available',
            };
        } catch (Throwable $e) {
            report($e);
        }
        $text = (string) __('public_assistant.status.'.$state, [], $locale);

        return new PublicToolResult('public_status', 'ok', [['type' => 'status', 'state' => $state, 'text' => $text]], ['service_status' => $state], [$text]);
    }

    private function supportHandoff(PublicAssistantContext $context): PublicToolResult
    {
        $locale = $context->locale;
        $email = trim((string) $this->settings->get(SystemSettingsRegistry::GROUP_GENERAL, 'support_email', ''));
        $phone = trim((string) $this->settings->get(SystemSettingsRegistry::GROUP_GENERAL, 'support_phone', ''));
        $contacts = [];
        if ($phone !== '') {
            $contacts[] = ['kind' => 'phone', 'label' => $phone, 'href' => 'tel:'.preg_replace('/[^+0-9]/', '', $phone)];
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contacts[] = ['kind' => 'email', 'label' => $email, 'href' => 'mailto:'.$email];
        }
        $blocks = [['type' => 'text', 'text' => __('public_assistant.answers.support_intro', [], $locale)]];
        if ($contacts !== []) {
            $blocks[] = ['type' => 'contact', 'items' => $contacts];
        } else {
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => __('public_assistant.answers.contact_unavailable', [], $locale)];
        }
        $target = $context->page['target'];
        if ($target !== null && $target['phone'] !== null) {
            $blocks[] = ['type' => 'contact', 'items' => [['kind' => 'phone', 'label' => $target['name'].' · '.$target['phone']['display'], 'href' => $target['phone']['href']]]];
        }
        $blocks[] = ['type' => 'actions', 'items' => PublicAssistantPageRegistry::navigation($locale, ['support'])];

        return new PublicToolResult('public_support_handoff', 'ok', $blocks, ['support_contacts' => count($contacts)], [__('public_assistant.answers.support_intro', [], $locale)]);
    }

    /** The scanned target's own form links, labelled; nothing when no target was resolved. @return list<array{key: string, label: string, href: string}> */
    private function targetActions(PublicAssistantContext $context): array
    {
        $target = $context->page['target'];
        if ($target === null) {
            return [];
        }
        $items = [];
        foreach (['feedback', 'suggestion', 'complaint'] as $key) {
            if (isset($target['links'][$key])) {
                $items[] = ['key' => 'submit_'.$key, 'label' => (string) __('public_assistant.quick_actions.submit_'.$key, [], $context->locale), 'href' => $target['links'][$key]];
            }
        }

        return $items;
    }
}
