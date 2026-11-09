<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Assistant;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\IntelligenceIncident;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Assistant\AssistantContextResolver;
use App\Services\Assistant\AssistantConversations;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\AssistantSettings;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON endpoints of the assistant (docs/assistant/context-and-tools.md).
 * Thin: validation, the resolver, one service call, typed errors.
 */
final class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantSettings $settings,
        private readonly AssistantContextResolver $resolver,
        private readonly AssistantConversations $conversations,
        private readonly AssistantService $assistant,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        /** @var User $user */
        $user = $request->user();
        $page = $request->validate(self::pageRules(''));
        $context = $this->resolver->resolve($user, $page);
        $conversation = $this->conversations->active($user);

        return response()->json([
            'enabled' => true,
            'status' => $context->status(),
            'ai' => ['available' => $context->aiAvailable],
            'capabilities' => $context->capabilities,
            'page' => $context->pageForClient(),
            'quick_actions' => $this->assistant->quickActions($context),
            'welcome' => __('assistant.welcome', [], $context->locale),
            'disclaimer' => __('assistant.disclaimer', [], $context->locale),
            'now_on' => __('assistant.now_on', ['module' => $context->page['module_label']], $context->locale),
            'limits' => ['max_message_chars' => $this->settings->maxMessageChars()],
            'conversation' => $conversation === null ? null : $this->conversationArray($conversation->load('messages')),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $items = AssistantConversation::query()->where('user_id', $request->user()->id)->orderByDesc('last_activity_at')->limit(20)->get()
            ->map(fn ($c): array => ['id' => $c->id, 'title' => $c->title, 'status' => $c->status, 'last_activity_at' => $c->last_activity_at->toIso8601String()]);

        return response()->json(['conversations' => $items]);
    }

    public function conversation(Request $request, string $conversation): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $found = $this->conversations->find($request->user(), $conversation);
        if ($found === null) {
            return $this->error('not_found', 404);
        }

        return response()->json(['conversation' => $this->conversationArray($found->load('messages'))]);
    }

    public function startConversation(Request $request): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $page = $request->validate(self::pageRules(''));
        $context = $this->resolver->resolve($request->user(), $page);
        $conversation = $this->conversations->start($request->user(), $context->page['module'], $context->page['route']);

        return response()->json(['conversation' => $this->conversationArray($conversation)], 201);
    }

    public function message(Request $request): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $max = $this->settings->maxMessageChars();
        $data = $request->validate([
            'conversation_id' => ['nullable', 'uuid'],
            'message' => ['required', 'string', 'min:1', 'max:'.$max],
            'action' => ['nullable', Rule::in(['explain_page', 'how_to', 'find', 'ask_module', 'report_problem', 'system_status', 'create_ticket'])],
            ...self::pageRules('page.'),
        ]);
        /** @var User $user */
        $user = $request->user();
        $context = $this->resolver->resolve($user, $data['page'] ?? []);
        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = $this->conversations->find($user, $data['conversation_id']);
            if ($conversation === null) {
                return $this->error('not_found', 404);
            }
        }

        $result = $this->assistant->answer($context, $conversation, $data['message'], $data['action'] ?? null);

        return response()->json([
            'conversation_id' => $result['conversation']->id,
            'status' => $context->status(),
            'message' => $result['user']->toClientArray(),
            'reply' => $result['reply']->toClientArray(),
        ]);
    }

    public function feedback(Request $request, string $message): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        $data = $request->validate(['value' => ['required', Rule::in(AssistantMessage::FEEDBACK)]]);
        $found = AssistantMessage::query()->whereKey($message)->where('role', 'assistant')
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $request->user()->id))->first();
        if ($found === null) {
            return $this->error('not_found', 404);
        }
        $found->forceFill(['feedback' => $data['value']])->save();

        return response()->json(['message' => $found->toClientArray()]);
    }

    /** Explicit ticket creation from the panel's offer (docs/assistant/support-helpdesk.md). */
    public function ticket(Request $request, SupportTicketService $tickets): JsonResponse
    {
        if ($disabled = $this->disabled()) {
            return $disabled;
        }
        if (! $this->settings->supportEnabled()) {
            return $this->error('support_disabled', 403);
        }
        $data = $request->validate([
            'conversation_id' => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'min:5', 'max:4000'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'error_reference' => ['nullable', 'string', 'max:40'],
            'incident_reference' => ['nullable', 'string', 'max:40'],
            ...self::pageRules('page.'),
        ]);
        /** @var User $user */
        $user = $request->user();
        $context = $this->resolver->resolve($user, $data['page'] ?? []);
        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = $this->conversations->find($user, $data['conversation_id']);
            if ($conversation === null) {
                return $this->error('not_found', 404);
            }
        }
        $incidentId = null;
        if (! empty($data['incident_reference'])) {
            $incidentId = IntelligenceIncident::query()->where('reference', strtoupper($data['incident_reference']))->value('id');
        }

        $ticket = $tickets->create($user, [
            'description' => $data['description'],
            'category' => $data['category'],
            'priority' => $data['priority'] ?? 'normal',
            'module' => $context->page['module'],
            'route_name' => $context->page['route'],
            'path' => $context->page['path'],
            'error_reference' => $data['error_reference'] ?? null,
            'incident_id' => $incidentId,
            'conversation_id' => $conversation?->id,
            'user_agent' => $request->userAgent(),
            'context' => ['route' => $context->page['route'], 'path' => $context->page['path'], 'module' => $context->page['module'], 'locale' => $context->locale, 'timestamp' => now()->toIso8601String(), 'error_reference' => $data['error_reference'] ?? null, 'incident_reference' => $data['incident_reference'] ?? null],
        ]);

        $block = ['type' => 'ticket', 'reference' => $ticket->reference, 'status' => $ticket->status, 'created_at' => $ticket->created_at->toIso8601String(), 'href' => '/support/tickets/'.$ticket->id];
        $text = __('assistant.answers.ticket_created', ['reference' => $ticket->reference, 'team' => $ticket->assigned_team ?? __('support.default_team', [], $context->locale)], $context->locale);
        $reply = null;
        if ($conversation !== null) {
            $reply = $this->conversations->append($conversation, 'assistant', $text, [$block], ['mode' => 'SUPPORT', 'tools' => ['support_ticket_create'], 'status' => 'ok'])->toClientArray();
        }

        return response()->json(['ticket' => $block, 'text' => $text, 'reply' => $reply], 201);
    }

    /** @return array<string, list<string>> */
    private static function pageRules(string $prefix): array
    {
        return [
            $prefix.'route' => ['nullable', 'string', 'max:190'],
            $prefix.'path' => ['nullable', 'string', 'max:255'],
            $prefix.'record_type' => ['nullable', 'string', 'max:40'],
            $prefix.'record_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    private function disabled(): ?JsonResponse
    {
        return $this->settings->enabled() ? null : $this->error('assistant_disabled', 403);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => __('assistant.errors.'.$code)], $status);
    }

    /** @return array<string, mixed> */
    private function conversationArray(AssistantConversation $conversation): array
    {
        return [
            'id' => $conversation->id, 'title' => $conversation->title, 'status' => $conversation->status,
            'messages' => $conversation->relationLoaded('messages') ? $conversation->messages->map(fn (AssistantMessage $m): array => $m->toClientArray())->all() : [],
        ];
    }
}
