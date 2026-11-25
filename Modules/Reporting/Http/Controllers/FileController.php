<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Consolidation;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUpUpdate;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every file of the module leaves through here. Files live on a private
 * disk and have no public address; access is checked again at the moment of
 * download (a link that was valid yesterday is refused today if the person's
 * scope has changed), and each download is audited.
 */
final class FileController extends ReportingController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function attachment(Request $request, string $attachment): StreamedResponse
    {
        $file = Attachment::query()->findOrFail($attachment);
        $obligation = $this->readable($this->actor($request), $file->obligation_id);
        // A file still in someone's draft belongs to the reporting side alone.
        if ($file->submission_id === null && ! ReportingGuard::onReportingSide($this->actor($request), $obligation)) {
            throw new ReportingAccessDenied(ReportingRoles::VIEW);
        }
        abort_unless(in_array($file->scan_status, ['clean', 'not_scanned'], true), 404);

        return $this->send($request, $file->disk, $file->path, $file->original_name, $file->mime, 'reporting.attachment', $file->id, $obligation);
    }

    public function artifact(Request $request, string $artifact): StreamedResponse
    {
        $file = Artifact::query()->findOrFail($artifact);
        abort_unless($file->status === 'completed' && $file->path !== null, 404);
        $actor = $this->actor($request);
        $obligation = null;
        if ($file->owner_type === 'submission') {
            $obligation = $this->readable($actor, Submission::query()->findOrFail($file->owner_id)->obligation_id);
            $name = "report-{$file->label}";
        } else {
            $consolidation = Consolidation::query()->findOrFail($file->owner_id);
            if (! $actor->can(ReportingRoles::CONSOLIDATE) || ! $actor->reaches($consolidation->parent_organization_id)) {
                throw new ReportingAccessDenied(ReportingRoles::CONSOLIDATE);
            }
            $name = 'consolidated-report';
        }
        $mime = $file->format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        return $this->send($request, (string) $file->disk, $file->path, "{$name}.{$file->format}", $mime, 'reporting.artifact', $file->id, $obligation, inline: $file->format === 'pdf');
    }

    public function evidence(Request $request, string $update): StreamedResponse
    {
        $record = FollowUpUpdate::query()->findOrFail($update);
        abort_if($record->evidence_path === null, 404);
        $followUp = FollowUp::query()->findOrFail($record->follow_up_id);
        $actor = $this->actor($request);
        $obligation = Obligation::query()->findOrFail($followUp->obligation_id);
        if (! ReportingGuard::canView($actor, $obligation) && (int) $followUp->owner_user_id !== $actor->userId) {
            throw new ReportingAccessDenied(ReportingRoles::VIEW);
        }

        return $this->send($request, (string) $record->evidence_disk, $record->evidence_path, (string) $record->evidence_name, 'application/octet-stream', 'reporting.follow_up_evidence', $record->id, $obligation);
    }

    /** Pick-list of organizations: only those the actor reaches, bounded. */
    public function organizations(Request $request, OrganizationDirectory $organizations): JsonResponse
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);

        return response()->json(['items' => array_map(fn ($o): array => $o->toArray(), $organizations->search($actor, trim((string) $request->query('q', '')), 20))]);
    }

    /** The work units of one institution the actor reaches, for naming a unit that must report. */
    public function units(Request $request, OrganizationDirectory $organizations): JsonResponse
    {
        $actor = $this->actor($request);
        $organizationId = (string) $request->query('organization_id', '');
        if (! ($actor->can(ReportingRoles::PUBLISH_SCHEDULES) || $actor->can(ReportingRoles::MANAGE_SETTINGS)) || $organizationId === '' || ! $actor->reaches($organizationId)) {
            throw new ReportingAccessDenied(ReportingRoles::PUBLISH_SCHEDULES);
        }

        return response()->json(['items' => array_values(array_map(fn ($u): array => $u->toArray(), $organizations->units($organizationId)))]);
    }

    /** Pick-list of people: only for those who assign responsibility, scoped and bounded. */
    public function users(Request $request, ReportingAccessResolver $access): JsonResponse
    {
        $actor = $this->actor($request);
        if (! array_intersect($actor->permissions, [ReportingRoles::PUBLISH_SCHEDULES, ReportingRoles::REVIEW, ReportingRoles::APPROVE, ReportingRoles::SUBMIT])) {
            throw new ReportingAccessDenied(ReportingRoles::VIEW);
        }
        $term = trim((string) $request->query('q', ''));

        return response()->json(['items' => mb_strlen($term) < 2 ? [] : array_map(fn ($u): array => $u->toArray(), $access->searchUsers($actor, $term, 15))]);
    }

    private function readable(ActorContext $actor, string $obligationId): Obligation
    {
        $obligation = Obligation::query()->findOrFail($obligationId);
        $confidentiality = (string) ReportType::query()->whereKey($obligation->report_type_id)->value('confidentiality');
        if (! ReportingGuard::canReadContent($actor, $obligation, $confidentiality)) {
            throw new ReportingAccessDenied(ReportingRoles::VIEW);
        }

        return $obligation;
    }

    private function send(Request $request, string $disk, string $path, string $name, string $mime, string $subjectType, string $subjectId, ?Obligation $obligation, bool $inline = false): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);
        $this->audit->record('file_downloaded', $this->actor($request), $subjectType, $subjectId, $obligation?->organization_id, ['name' => $name]);
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $inline ? Storage::disk($disk)->response($path, $name, $headers) : Storage::disk($disk)->download($path, $name, $headers);
    }
}
