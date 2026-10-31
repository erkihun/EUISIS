<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\IntelligenceIncident;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\Intelligence\AI\Redactor;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The support help desk's write side (docs/assistant/support-helpdesk.md).
 *
 * Creates tickets with a monthly sequential reference (TKT-YYMM-NNNN), a
 * SAFE diagnostic context (allow-listed keys, redacted string values, nothing
 * else), a user-chosen priority that only a linked critical/high incident may
 * raise, and a team chosen by category through config/support.php
 * (NEEDS_POLICY_DECISION_SUPPORT_ROUTING). Every change is an immutable
 * event row and an audit entry.
 */
final class SupportTicketService
{
    /** The only keys a ticket's context may carry. */
    public const CONTEXT_KEYS = ['route', 'path', 'module', 'locale', 'browser', 'os', 'device', 'error_reference', 'incident_reference', 'timestamp', 'viewport'];

    public const CONTEXT_VALUE_MAX = 200;

    public const DESCRIPTION_MAX = 4000;

    public const TITLE_FROM_DESCRIPTION = 120;

    public const ERROR_REFERENCE_PATTERN = '/^ERR-[A-Z0-9]{8,16}$/';

    /** Incident severities that raise a ticket's priority to high. */
    private const ESCALATING_SEVERITIES = ['critical', 'high'];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  description (required), category, priority, title, module, route_name, path,
     *                                      error_reference, incident_id, conversation_id, context (array), user_agent, locale
     */
    public function create(User $reporter, array $data): SupportTicket
    {
        $description = trim(Redactor::text(self::string($data['description'] ?? null) ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('A support ticket needs a description.');
        }
        $description = mb_substr($description, 0, self::DESCRIPTION_MAX);

        $category = self::string($data['category'] ?? null);
        $category = in_array($category, SupportTicket::CATEGORIES, true) ? $category : 'other';
        $priority = self::string($data['priority'] ?? null);
        $priority = in_array($priority, SupportTicket::PRIORITIES, true) ? $priority : 'normal';

        $title = trim(Redactor::text(self::string($data['title'] ?? null) ?? ''));
        if ($title === '') {
            $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $description)), 0, self::TITLE_FROM_DESCRIPTION);
        }
        $title = mb_substr($title, 0, 190);

        $errorReference = self::string($data['error_reference'] ?? null);
        if ($errorReference !== null && preg_match(self::ERROR_REFERENCE_PATTERN, $errorReference) !== 1) {
            $errorReference = null;
        }

        $incident = $this->incident(self::string($data['incident_id'] ?? null));
        if ($incident !== null && in_array($incident->severity, self::ESCALATING_SEVERITIES, true)) {
            $priority = 'high';
        }

        $conversationId = self::string($data['conversation_id'] ?? null);
        $conversationId = $conversationId !== null && Str::isUuid($conversationId) ? $conversationId : null;

        $module = self::limit(self::string($data['module'] ?? null), 60);
        $routeName = self::limit(self::string($data['route_name'] ?? null), 190);
        $path = self::limit(self::string($data['path'] ?? null), 255);

        $context = $this->sanitizeContext(
            [
                ...(is_array($data['context'] ?? null) ? $data['context'] : []),
                'route' => $routeName,
                'path' => $path,
                'module' => $module,
                'locale' => self::string($data['locale'] ?? null),
                'error_reference' => $errorReference,
                'incident_reference' => $incident?->reference,
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ],
            self::string($data['user_agent'] ?? null),
        );

        return DB::transaction(function () use ($reporter, $description, $category, $priority, $title, $errorReference, $incident, $conversationId, $module, $routeName, $path, $context): SupportTicket {
            $now = CarbonImmutable::now();
            $ticket = SupportTicket::query()->create([
                'reference' => $this->reference(),
                'reporter_user_id' => $reporter->getKey(),
                'organization_id' => $this->scope->assignedOrganizationIds($reporter)[0] ?? null,
                'module' => $module,
                'route_name' => $routeName,
                'path' => $path,
                'category' => $category,
                'priority' => $priority,
                'title' => $title,
                'description' => $description,
                'context' => $context === [] ? null : $context,
                'error_reference' => $errorReference,
                'incident_id' => $incident?->getKey(),
                'conversation_id' => $conversationId,
                'status' => 'open',
                'assigned_team' => $this->teamFor($category),
                'assigned_user_id' => null,
            ]);

            $this->event($ticket, 'created', null, 'open', $reporter, $now);
            $this->audit->execute(AuditEventType::SupportTicketCreated, $reporter, $ticket, $ticket->organization_id, newValues: [
                'reference' => $ticket->reference, 'category' => $category, 'priority' => $priority, 'status' => 'open',
                'assigned_team' => $ticket->assigned_team, 'module' => $module, 'route_name' => $routeName,
                'error_reference' => $errorReference, 'incident_reference' => $incident?->reference,
            ]);

            return $ticket;
        });
    }

    /** Moves the ticket to $status (no-op when unchanged); the note, if any, rides on the status event. */
    public function transition(SupportTicket $ticket, string $status, User $actor, ?string $note = null): SupportTicket
    {
        if (! in_array($status, SupportTicket::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown support ticket status [{$status}].");
        }
        $note = self::cleanNote($note);
        if ($status === $ticket->status) {
            if ($note !== null) {
                $this->note($ticket, $note, $actor);
            }

            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $status, $actor, $note): SupportTicket {
            $now = CarbonImmutable::now();
            $from = $ticket->status;
            $ticket->forceFill([
                'status' => $status,
                'resolved_at' => $status === 'resolved' ? $now : ($status === 'closed' ? ($ticket->resolved_at ?? $now) : null),
                'closed_at' => $status === 'closed' ? $now : null,
            ])->save();

            $this->event($ticket, 'status_changed', $from, $status, $actor, $now, $note);
            $this->audit->execute(AuditEventType::SupportTicketUpdated, $actor, $ticket, $ticket->organization_id,
                oldValues: ['status' => $from], newValues: ['status' => $status, 'action' => 'status_changed', 'reference' => $ticket->reference]);

            return $ticket;
        });
    }

    /** Sets the assignee (null = unassigned) and, when $team is given, the team; records one `assigned` event. */
    public function assign(SupportTicket $ticket, ?User $assignee, ?string $team, User $actor): SupportTicket
    {
        $team = self::limit(self::string($team), 60);
        $newUserId = $assignee === null ? null : (int) $assignee->getKey();
        $newTeam = $team ?? $ticket->assigned_team;
        $currentUserId = $ticket->assigned_user_id === null ? null : (int) $ticket->assigned_user_id;
        if ($currentUserId === $newUserId && $ticket->assigned_team === $newTeam) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $assignee, $newUserId, $newTeam, $actor): SupportTicket {
            $now = CarbonImmutable::now();
            $before = ['assigned_user_id' => $ticket->assigned_user_id, 'assigned_team' => $ticket->assigned_team];
            $fromLabel = self::assignmentLabel($ticket->assignee?->name, $ticket->assigned_team);
            $ticket->forceFill(['assigned_user_id' => $newUserId, 'assigned_team' => $newTeam])->save();
            $ticket->unsetRelation('assignee');

            $this->event($ticket, 'assigned', $fromLabel, self::assignmentLabel($assignee?->name, $newTeam), $actor, $now);
            $this->audit->execute(AuditEventType::SupportTicketUpdated, $actor, $ticket, $ticket->organization_id,
                oldValues: $before, newValues: ['assigned_user_id' => $newUserId, 'assigned_team' => $newTeam, 'action' => 'assigned', 'reference' => $ticket->reference]);

            return $ticket;
        });
    }

    /** Adds a note to the timeline without changing the ticket. */
    public function note(SupportTicket $ticket, string $note, User $actor): SupportTicketEvent
    {
        $note = self::cleanNote($note);
        if ($note === null) {
            throw new InvalidArgumentException('A note needs text.');
        }

        return DB::transaction(function () use ($ticket, $note, $actor): SupportTicketEvent {
            $event = $this->event($ticket, 'note', null, null, $actor, CarbonImmutable::now(), $note);
            $ticket->touch();
            $this->audit->execute(AuditEventType::SupportTicketUpdated, $actor, $ticket, $ticket->organization_id,
                newValues: ['action' => 'note', 'reference' => $ticket->reference]);

            return $event;
        });
    }

    /** The team a category routes to (config/support.php — NEEDS_POLICY_DECISION_SUPPORT_ROUTING). */
    public function teamFor(string $category): string
    {
        $routing = config('support.routing', []);
        $team = is_array($routing) ? ($routing[$category] ?? null) : null;
        $team = is_string($team) && trim($team) !== '' ? trim($team) : (string) config('support.default_team', 'Service Desk');

        return mb_substr($team, 0, 60);
    }

    /**
     * Keeps only CONTEXT_KEYS, as redacted strings of at most CONTEXT_VALUE_MAX
     * characters; browser/os/device always come from the User-Agent summary,
     * never from the caller. Cookies, tokens, headers, HTML and form payloads
     * cannot pass because their keys are not on the list.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, string>
     */
    public function sanitizeContext(array $context, ?string $userAgent = null): array
    {
        $ua = UserAgent::summarize($userAgent);
        $context = [...$context, 'browser' => $ua['browser'], 'os' => $ua['os'], 'device' => $ua['device'] === UserAgent::UNKNOWN ? null : $ua['device']];

        $clean = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (! is_string($value)) {
                continue;
            }
            $value = trim(strip_tags(Redactor::text($value)));
            if ($value === '') {
                continue;
            }
            $clean[$key] = mb_substr($value, 0, self::CONTEXT_VALUE_MAX);
        }

        return $clean;
    }

    private function event(SupportTicket $ticket, string $event, ?string $from, ?string $to, ?User $actor, CarbonImmutable $at, ?string $note = null): SupportTicketEvent
    {
        return SupportTicketEvent::query()->create([
            'ticket_id' => $ticket->getKey(),
            'event' => $event,
            'from_value' => self::limit($from, 190),
            'to_value' => self::limit($to, 190),
            'note' => $note,
            'actor_user_id' => $actor?->getKey(),
            'occurred_at' => $at,
            'created_at' => $at,
        ]);
    }

    private function incident(?string $id): ?IntelligenceIncident
    {
        if ($id === null || ! Str::isUuid($id)) {
            return null;
        }

        return IntelligenceIncident::query()->whereKey($id)->first(['id', 'reference', 'severity', 'status']);
    }

    /** From the support-ticket code rule (TKT-YYMM-NNNN by default). */
    private function reference(): string
    {
        return app(GenerateCodeAction::class)->execute(CodeRuleEntityType::SupportTicket, [], null, null, 'reference');
    }

    private static function assignmentLabel(?string $user, ?string $team): ?string
    {
        $parts = array_values(array_filter([$user, $team], fn (?string $p): bool => $p !== null && $p !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = trim(Redactor::text($note));

        return $note === '' ? null : mb_substr($note, 0, self::DESCRIPTION_MAX);
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function limit(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
