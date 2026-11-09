<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves stamped copies and evidence from the private disk, only through the policy, always as an attachment. */
final class AttachmentController extends Controller
{
    public function __invoke(Request $request, MonitoringAttachment $attachment, ServiceMonitoringAudit $audit): StreamedResponse
    {
        $this->authorize('download', $attachment);
        $audit->record(AuditEventType::ServiceMonitoringAttachmentDownloaded, $request->user(), $attachment, null,
            ['attachable_type' => $attachment->attachable_type, 'attachable_id' => $attachment->attachable_id, 'sha256' => $attachment->sha256]);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
