<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PublicAssistant\PublicAssistantContextResolver;
use App\Services\PublicAssistant\PublicAssistantConversation;
use App\Services\PublicAssistant\PublicAssistantService;
use App\Services\PublicAssistant\PublicAssistantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Anonymous JSON endpoints of Addis One Help (docs/public-assistant/public-capabilities.md).
 * Thin: validation, the public resolver, one service call, typed errors,
 * no-store headers. Never reads an authenticated user.
 */
final class PublicAssistantController extends Controller
{
    private const ACTIONS = ['how_to_use', 'submit_feedback', 'submit_suggestion', 'submit_complaint', 'track_submission', 'verify_employee', 'organization_services', 'contact', 'public_help'];

    public function __construct(
        private readonly PublicAssistantSettings $settings,
        private readonly PublicAssistantContextResolver $resolver,
        private readonly PublicAssistantConversation $conversation,
        private readonly PublicAssistantService $assistant,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $page = $request->validate(self::pageRules(''));
        $context = $this->resolver->resolve($page);

        return $this->json([
            'enabled' => true,
            'status' => $context->status(),
            'capabilities' => $context->capabilities,
            'page' => $context->pageForClient(),
            'quick_actions' => $this->assistant->quickActions($context),
            'welcome' => __('public_assistant.welcome', [], $context->locale),
            'disclaimer' => __('public_assistant.disclaimer', [], $context->locale),
            'limits' => ['max_message_chars' => $this->settings->maxMessageChars()],
            'messages' => $this->conversation->turns($request->session()),
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:'.$this->settings->maxMessageChars()],
            'action' => ['nullable', Rule::in(self::ACTIONS)],
            ...self::pageRules('page.'),
        ]);
        $context = $this->resolver->resolve($data['page'] ?? []);
        $result = $this->assistant->answer($request->session(), $context, $data['message'], $data['action'] ?? null);

        return $this->json(['status' => $context->status(), 'message' => $result['user'], 'reply' => $result['reply']]);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->conversation->reset($request->session());

        return $this->json(['ok' => true]);
    }

    /** @return array<string, list<string>> */
    private static function pageRules(string $prefix): array
    {
        return [
            $prefix.'route' => ['nullable', 'string', 'max:120'],
            $prefix.'path' => ['nullable', 'string', 'max:255'],
            $prefix.'token' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    private function disabled(): ?JsonResponse
    {
        return $this->settings->enabled() ? null : $this->json(['error' => 'assistant_disabled', 'message' => __('public_assistant.errors.assistant_disabled')], 403);
    }

    /** @param  array<string, mixed>  $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}
