<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\Conversation;
use App\Services\Communication\CommunicationInbox;
use App\Services\Communication\CommunicationSettings;
use App\Services\Communication\ConversationList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The chat workspace (conversation list, active conversation, details panel). */
class MessagesController extends Controller
{
    public function __construct(
        private readonly ConversationList $list,
        private readonly CommunicationInbox $inbox,
        private readonly CommunicationSettings $settings,
    ) {}

    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $user = $request->user();
        if ($conversation !== null) {
            // A guessed id of someone else's conversation is a plain 404, never a hint that it exists.
            abort_unless(Gate::forUser($user)->allows('view', $conversation), 404);
        }
        $this->inbox->markDelivered($user);
        $type = $request->query('type');

        return Inertia::render('Communication/Messages', [
            'initialConversations' => $this->list->page($user, is_string($type) ? $type : null, null, app()->getLocale()),
            'activeConversationId' => $conversation?->getKey(),
            'filter' => is_string($type) ? $type : null,
            'limits' => [
                'maxAttachmentKb' => $this->settings->maxAttachmentKb(),
                'allowedTypes' => $this->settings->allowedAttachmentTypes(),
                'maxBody' => 10000,
                'editWindowMinutes' => $this->settings->allowEditing() ? $this->settings->editWindowMinutes() : 0,
                'deleteWindowMinutes' => $this->settings->allowDeletion() ? $this->settings->deleteWindowMinutes() : 0,
            ],
            'reactions' => (array) config('communication.reactions', []),
            'can' => [
                'message' => $user->can('messages.send'),
                'upload' => $user->can('attachments.upload'),
                'forward' => $user->can('messages.forward') && $this->settings->allowForwarding(),
                'createGroup' => $user->can('groups.create') && $this->settings->allowEmployeeGroups(),
                'createTask' => $user->can('communication.tasks.create'),
                'report' => $user->can('communication.reports.create'),
            ],
            'realtime' => [
                'enabled' => ! in_array(config('broadcasting.default'), [null, 'null', 'log'], true),
            ],
        ]);
    }
}
