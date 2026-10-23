<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Communication\AcknowledgeAnnouncementAction;
use App\Actions\Communication\CancelAnnouncementAction;
use App\Actions\Communication\PublishAnnouncementAction;
use App\Actions\Communication\SaveAnnouncementAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\AnnouncementPriority;
use App\Enums\Communication\AnnouncementTargetType;
use App\Enums\Communication\ConversationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\CancelAnnouncementRequest;
use App\Http\Requests\Communication\SaveAnnouncementRequest;
use App\Models\Communication\Announcement;
use App\Models\Communication\AnnouncementAttachment;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Communication\AnnouncementAudience;
use App\Services\Communication\AnnouncementPresenter;
use App\Services\Communication\AnnouncementTargeting;
use App\Services\Communication\AttachmentStorage;
use App\Services\Communication\CommunicationInbox;
use App\Services\Communication\CommunicationSettings;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Official announcements: the reader's feed and page, and the authoring,
 * publishing and reporting side for the people who manage them.
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly AnnouncementPresenter $presenter,
        private readonly AnnouncementAudience $audience,
        private readonly AnnouncementTargeting $targeting,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('viewAny', Announcement::class), 403);
        $locale = app()->getLocale();
        $manages = $this->managesAny($user);

        return Inertia::render('Communication/Announcements/Index', [
            'feed' => $this->presenter->feed($user, 'all', null, $locale),
            'managed' => $manages ? $this->presenter->managed($user, null, null, $locale) : null,
            'can' => ['create' => $user->can('announcements.create'), 'manage' => $manages],
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('viewAny', Announcement::class), 403);
        $filter = in_array($request->query('filter'), ['unread', 'acknowledge'], true) ? (string) $request->query('filter') : 'all';

        return response()->json($this->presenter->feed($request->user(), $filter, $request->string('cursor')->toString() ?: null, app()->getLocale()));
    }

    public function managed(Request $request): JsonResponse
    {
        abort_unless($this->managesAny($request->user()), 403);

        return response()->json($this->presenter->managed($request->user(), $request->string('status')->toString() ?: null,
            $request->string('cursor')->toString() ?: null, app()->getLocale()));
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('announcements.create'), 403);

        return Inertia::render('Communication/Announcements/Form', ['announcement' => null, ...$this->formOptions($request->user())]);
    }

    public function store(SaveAnnouncementRequest $request, SaveAnnouncementAction $save, PublishAnnouncementAction $publish): RedirectResponse
    {
        $announcement = $save->execute($request->user(), $request->validated());

        return $this->afterSave($request, $announcement, $publish);
    }

    public function edit(Request $request, Announcement $announcement): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('update', $announcement), 404);

        return Inertia::render('Communication/Announcements/Form', [
            'announcement' => [
                'id' => $announcement->getKey(),
                'status' => $announcement->status->value,
                'title' => $announcement->title, 'title_am' => $announcement->title_am,
                'body' => $announcement->body, 'body_am' => $announcement->body_am,
                'priority' => $announcement->priority->value,
                'acknowledgement_required' => $announcement->acknowledgement_required,
                'expires_at' => $announcement->expires_at?->toIso8601String(),
                'publish_at' => $announcement->publish_at?->toIso8601String(),
                'targets' => $this->presenter->targetLabels($announcement, app()->getLocale()),
                'attachments' => $announcement->attachments()->orderBy('created_at')->get()->map(fn (AnnouncementAttachment $f) => [
                    'id' => $f->getKey(), 'name' => $f->original_name, 'size' => $f->size, 'extension' => $f->extension, 'downloadable' => $f->scan_status->isDownloadable(),
                ])->values(),
            ],
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement, SaveAnnouncementAction $save, PublishAnnouncementAction $publish): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('manage', $announcement), 404);
        $save->execute($request->user(), $request->validated(), $announcement);

        return $this->afterSave($request, $announcement->fresh(), $publish);
    }

    public function show(Request $request, Announcement $announcement): Response
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('view', $announcement), 404);
        if ($announcement->isLive() && $this->audience->reaches($user, $announcement)) {
            AcknowledgeAnnouncementAction::touch($user, $announcement, ['delivered_at', 'viewed_at']);
            app(CommunicationInbox::class)->forgetTotals($user->getKey());
        }

        return Inertia::render('Communication/Announcements/Show', [
            'announcement' => $this->presenter->detail($announcement, $user, app()->getLocale()),
        ]);
    }

    public function publish(Request $request, Announcement $announcement, PublishAnnouncementAction $publish): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('manage', $announcement), 404);
        $request->validate(['publish_at' => ['nullable', 'date', 'after:now']]);
        $at = $request->filled('publish_at') ? Carbon::parse((string) $request->input('publish_at')) : null;
        $publish->execute($request->user(), $announcement, $at);

        return back()->with('success', __($at ? 'communication.flash.announcement_scheduled' : 'communication.flash.announcement_published'));
    }

    public function cancel(CancelAnnouncementRequest $request, Announcement $announcement, CancelAnnouncementAction $cancel): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('manage', $announcement), 404);
        $cancel->execute($request->user(), $announcement, (string) $request->validated('reason'));

        return back()->with('success', __('communication.flash.announcement_cancelled'));
    }

    public function acknowledge(Request $request, Announcement $announcement, AcknowledgeAnnouncementAction $acknowledge): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $announcement), 404);
        $acknowledge->execute($request->user(), $announcement);

        return back()->with('success', __('communication.flash.announcement_acknowledged'));
    }

    /** Who it reached and where each person stands, a page at a time. */
    public function recipients(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('manage', $announcement), 404);
        $cursor = $request->string('cursor')->toString();
        $query = $this->recipientRows($announcement, (string) $request->query('status', 'all'), trim($request->string('q')->toString()));
        if ($cursor !== '' && ($decoded = json_decode((string) base64_decode($cursor, true), true)) && count($decoded) === 2) {
            [$name, $id] = $decoded;
            $query->where(fn ($q) => $q->where('users.name', '>', $name)->orWhere(fn ($e) => $e->where('users.name', $name)->where('users.id', '>', (int) $id)));
        }
        $rows = $query->limit(51)->get();
        $more = $rows->count() > 50;
        $rows = $rows->take(50);
        $last = $rows->last();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->recipientRow($r))->values(),
            'next_cursor' => $more && $last ? base64_encode((string) json_encode([$last->name, $last->id])) : null,
        ]);
    }

    public function export(Request $request, Announcement $announcement, WriteAuditLogAction $audit): StreamedResponse
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('manage', $announcement) && Gate::forUser($user)->allows('exportAcknowledgements', $announcement), 404);
        $status = (string) $request->query('status', 'all');
        $audit->execute(AuditEventType::CommunicationAcknowledgementsExported, $user, $announcement, $announcement->organization_id, null, ['status' => $status]);
        $query = $this->recipientRows($announcement, $status, '');
        $headers = (array) __('communication.export.headers');

        return response()->streamDownload(function () use ($query, $headers): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 (Amharic) correctly with a BOM.
            fputcsv($out, $headers);
            $query->chunk(1000, function ($rows) use ($out): void {
                foreach ($rows as $r) {
                    $row = $this->recipientRow($r);
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, [
                        $row['name'], $row['employee_number'], $row['organization'], $row['delivered_at'], $row['viewed_at'], $row['acknowledged_at'],
                    ]));
                }
            });
            fclose($out);
        }, 'announcement-'.$announcement->getKey().'-acknowledgements.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** How many people a set of targets would reach, for the author before publishing. */
    public function audienceCount(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('announcements.create') || $user->can('announcements.edit'), 403);
        $request->validate(['targets' => ['required', 'array', 'min:1', 'max:'.AnnouncementTargeting::MAX_TARGETS], 'targets.*.type' => ['required', 'string'], 'targets.*.id' => ['nullable', 'string', 'max:64']]);
        $resolved = $this->targeting->resolve($user, $request->input('targets'), $user->can('announcements.create') ? 'announcements.create' : 'announcements.edit');

        return response()->json(['count' => $this->audience->recipients(null, $resolved['targets'])->count()]);
    }

    /** Search the things an author may target (never a list of every employee). */
    public function targetOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('announcements.create') || $user->can('announcements.edit'), 403);
        $type = AnnouncementTargetType::tryFrom((string) $request->query('type'));
        $term = trim($request->string('q')->toString());
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $am = app()->getLocale() === 'am';
        $label = fn ($row) => $am && filled($row->name_am ?? null) ? $row->name_am : $row->name_en;

        $data = match ($type) {
            AnnouncementTargetType::Organization, AnnouncementTargetType::OrganizationSubtree => $this->scope
                ->applyOrganizationScope(Organization::query()->select(['id', 'name_en', 'name_am', 'code']), $user, 'id')
                ->when(mb_strlen($term) >= 2, fn ($q) => $q->where(fn ($w) => $w->where('name_en', ci_like_operator(), $like)->orWhere('name_am', ci_like_operator(), $like)->orWhere('code', ci_like_operator(), $like)))
                ->orderBy('name_en')->limit(20)->get()->map(fn ($o) => ['id' => $o->id, 'name' => $label($o), 'hint' => $o->code]),
            AnnouncementTargetType::OrganizationUnit => $request->filled('organization_id') && $this->scope->canAccessOrganization($user, (string) $request->query('organization_id'))
                ? OrganizationUnit::query()->where('organization_id', (string) $request->query('organization_id'))->select(['id', 'name_en', 'name_am', 'code'])
                    ->when(mb_strlen($term) >= 2, fn ($q) => $q->where(fn ($w) => $w->where('name_en', ci_like_operator(), $like)->orWhere('name_am', ci_like_operator(), $like)))
                    ->orderBy('name_en')->limit(30)->get()->map(fn ($u) => ['id' => $u->id, 'name' => $label($u), 'hint' => $u->code])
                : collect(),
            AnnouncementTargetType::Employee => mb_strlen($term) < 2 ? collect() : $this->employeesInScope($user)
                ->where(fn ($w) => $w->where('employees.full_name', ci_like_operator(), $like)->orWhere('employees.name_en', ci_like_operator(), $like)->orWhere('employees.employee_number', ci_like_operator(), $like))
                ->orderBy('employees.name_en')->limit(20)->get(['employees.id', 'employees.full_name', 'employees.name_en', 'employees.employee_number'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $am ? ($e->full_name ?: $e->name_en) : ($e->name_en ?: $e->full_name), 'hint' => $e->employee_number]),
            AnnouncementTargetType::Role => $this->scope->canExercisePermission($user, 'announcements.create', null)
                ? Role::query()->when(mb_strlen($term) >= 2, fn ($q) => $q->where('name', ci_like_operator(), $like))->orderBy('name')->limit(30)->get(['id', 'name'])
                    ->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name, 'hint' => null])
                : collect(),
            AnnouncementTargetType::Group => Conversation::query()->where('type', ConversationType::Group->value)->whereNull('archived_at')
                ->whereIn('id', ConversationMember::query()->select('conversation_id')->where('user_id', $user->getKey())->whereNull('left_at'))
                ->when(mb_strlen($term) >= 2, fn ($q) => $q->where('title', ci_like_operator(), $like))
                ->orderBy('title')->limit(20)->get(['id', 'title'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->title, 'hint' => null]),
            default => collect(),
        };

        return response()->json(['data' => $data->values()]);
    }

    public function storeAttachment(Request $request, Announcement $announcement, AttachmentStorage $storage): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('update', $announcement), 404);
        $request->validate(['file' => ['required', 'file']]);
        abort_if($announcement->attachments()->count() >= 10, 422, __('communication.errors.too_many_attachments'));
        $stored = $storage->store($request->file('file'), 'announcements/'.$announcement->getKey());
        unset($stored['metadata']);
        $file = (new AnnouncementAttachment)->forceFill([...$stored, 'announcement_id' => $announcement->getKey(), 'uploaded_by' => $request->user()->getKey()]);
        $file->save();

        return response()->json(['data' => [
            'id' => $file->getKey(), 'name' => $file->original_name, 'size' => $file->size, 'extension' => $file->extension, 'downloadable' => $file->scan_status->isDownloadable(),
        ]], 201);
    }

    public function destroyAttachment(Request $request, AnnouncementAttachment $attachment): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('update', $attachment->announcement), 404);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(['deleted' => true]);
    }

    public function downloadAttachment(Request $request, AnnouncementAttachment $attachment): StreamedResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $attachment->announcement), 404);
        abort_unless($attachment->scan_status->isDownloadable() && Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], 'attachment');
    }

    private function afterSave(SaveAnnouncementRequest $request, Announcement $announcement, PublishAnnouncementAction $publish): RedirectResponse
    {
        $intent = $request->validated('intent') ?? 'draft';
        $to = redirect()->route('communication.announcements.show', $announcement);
        if ($intent === 'draft') {
            return $to->with('success', __('communication.flash.announcement_saved'));
        }
        if (! Gate::forUser($request->user())->allows('publish', $announcement)) {
            // Saved; publishing is someone else's step (for example HR drafts, the organization's admin publishes).
            return $to->with('warning', __('communication.flash.announcement_saved_for_publisher'));
        }
        $at = $intent === 'schedule' ? Carbon::parse((string) $request->validated('publish_at')) : null;
        $publish->execute($request->user(), $announcement, $at);

        return $to->with('success', __($at ? 'communication.flash.announcement_scheduled' : 'communication.flash.announcement_published'));
    }

    /** @return array<string, mixed> */
    private function formOptions(User $user): array
    {
        $cityWide = $this->scope->canExercisePermission($user, 'announcements.create', null) || $this->scope->canExercisePermission($user, 'announcements.edit', null);

        return [
            'priorities' => AnnouncementPriority::values(),
            'targetTypes' => array_values(array_filter(AnnouncementTargetType::values(),
                fn (string $t) => $cityWide || ! in_array($t, [AnnouncementTargetType::City->value, AnnouncementTargetType::Role->value], true))),
            'canPublish' => $user->can('announcements.publish'),
            'limits' => ['maxAttachmentKb' => app(CommunicationSettings::class)->maxAttachmentKb(),
                'allowedTypes' => app(CommunicationSettings::class)->allowedAttachmentTypes()],
        ];
    }

    private function managesAny(User $user): bool
    {
        return $user->can('announcements.create') || $user->can('announcements.edit') || $user->can('announcements.publish') || $user->can('announcements.cancel');
    }

    private function employeesInScope(User $user): Builder
    {
        $query = Employee::query()->whereNotNull('employees.current_assignment_id');
        $inScope = $this->scope->employeeIdsInScope($user);

        return $inScope === null ? $query : $query->whereIn('employees.id', $inScope);
    }

    private function recipientRows(Announcement $announcement, string $status, string $term): Builder
    {
        $query = $this->audience->recipients($announcement)
            ->leftJoin('announcement_receipts as r', fn ($j) => $j->on('r.user_id', '=', 'users.id')->where('r.announcement_id', '=', $announcement->getKey()))
            ->leftJoin('employees', 'employees.id', '=', 'users.employee_id')
            ->leftJoin('employee_assignments as ea', 'ea.id', '=', 'employees.current_assignment_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'ea.organization_id')
            ->select(['users.id', 'users.name', 'employees.employee_number', 'o.name_en as organization_en', 'o.name_am as organization_am',
                'r.delivered_at', 'r.viewed_at', 'r.acknowledged_at'])
            ->orderBy('users.name')->orderBy('users.id');
        match ($status) {
            'acknowledged' => $query->whereNotNull('r.acknowledged_at'),
            'pending' => $query->whereNull('r.acknowledged_at'),
            'viewed' => $query->whereNotNull('r.viewed_at'),
            'not_viewed' => $query->whereNull('r.viewed_at'),
            default => null,
        };
        if (mb_strlen($term) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
            $query->where(fn ($w) => $w->where('users.name', ci_like_operator(), $like)->orWhere('employees.employee_number', ci_like_operator(), $like));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function recipientRow(object $r): array
    {
        $at = fn ($v) => $v === null ? null : Carbon::parse($v)->toIso8601String();

        return [
            'id' => (int) $r->id,
            'name' => $r->name,
            'employee_number' => $r->employee_number,
            'organization' => app()->getLocale() === 'am' && filled($r->organization_am) ? $r->organization_am : $r->organization_en,
            'delivered_at' => $at($r->delivered_at),
            'viewed_at' => $at($r->viewed_at),
            'acknowledged_at' => $at($r->acknowledged_at),
        ];
    }
}
