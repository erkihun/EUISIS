<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use Illuminate\Contracts\Session\Session;
use Throwable;

/** One anonymous turn: route, run the allowed public tools, compose, remember in the session. */
final class PublicAssistantService
{
    public function __construct(
        private readonly PublicAssistantIntentRouter $router,
        private readonly PublicAssistantToolRegistry $tools,
        private readonly PublicAssistantAnswerComposer $composer,
        private readonly PublicAssistantConversation $conversation,
    ) {}

    /** @return list<array{key: string, label: string}> */
    public function quickActions(PublicAssistantContext $context): array
    {
        $keys = ['how_to_use'];
        $kind = $context->kind();
        $target = $context->page['target'];
        if ($target !== null) {
            $keys = [...$keys, 'submit_feedback', 'submit_suggestion', 'submit_complaint'];
            if ($target['type'] === 'organization' && $context->can('services')) {
                $keys[] = 'organization_services';
            }
        } elseif (in_array($kind, ['home', 'support', 'unknown'], true)) {
            $keys[] = 'submit_feedback';
        }
        $keys[] = 'track_submission';
        if (in_array($kind, ['verify', 'id_checker', 'home'], true)) {
            $keys[] = 'verify_employee';
        }
        if ($context->can('support')) {
            $keys[] = 'contact';
        }
        if ($context->can('knowledge') && $kind !== 'support') {
            $keys[] = 'public_help';
        }

        return array_map(fn (string $key): array => ['key' => $key, 'label' => (string) __('public_assistant.quick_actions.'.$key, [], $context->locale)], array_values(array_unique($keys)));
    }

    /** @return array{user: array<string, mixed>, reply: array<string, mixed>} */
    public function answer(Session $session, PublicAssistantContext $context, string $message, ?string $action): array
    {
        $history = $this->conversation->window($session);
        $user = $this->conversation->append($session, 'user', $message);
        $route = $this->router->route($action, $message, $context);
        $locale = $context->locale;
        $results = [];
        $narrate = true;

        if ($route['mode'] === PublicAssistantIntentRouter::DENIED) {
            $narrate = false;
            $results[] = new PublicToolResult('refusal', 'denied', [
                ['type' => 'notice', 'tone' => 'info', 'text' => __('public_assistant.answers.denied', [], $locale)],
            ], [], [__('public_assistant.answers.denied', [], $locale)]);
            foreach (['public_feedback_help', 'public_support_handoff'] as $tool) {
                $alt = $this->tools->run($tool, $context);
                if ($alt !== null) {
                    $results[] = $alt;
                }
            }
        } else {
            foreach ($route['tools'] as $tool) {
                try {
                    $result = $this->tools->run($tool, $context, $message);
                } catch (Throwable $e) {
                    report($e);
                    $result = null;
                }
                if ($result !== null) {
                    $results[] = $result;
                }
            }
            if ($results === []) {
                $results[] = PublicToolResult::text('none', __('public_assistant.answers.knowledge_empty', [], $locale), 'empty');
                $fallback = $this->tools->run('public_support_handoff', $context);
                if ($fallback !== null) {
                    $results[] = $fallback;
                }
                $narrate = false;
            }
        }

        $composed = $this->composer->compose($context, $route['mode'], $message, $results, $history, $narrate);
        if ($composed['ai']) {
            $composed['blocks'][] = ['type' => 'disclaimer', 'text' => __('public_assistant.disclaimer', [], $locale)];
        }
        $reply = $this->conversation->append($session, 'assistant', $composed['text'], $composed['blocks'], ['mode' => $route['mode'], 'ai' => $composed['ai']]);

        return ['user' => $user, 'reply' => $reply];
    }
}
