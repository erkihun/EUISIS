<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Application\Actions\AddAttachment;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\AttachmentKind;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterAttachment;
use Modules\Letters\Http\Requests\UploadAttachmentRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Uploads scans and annexes; serves files from the private disk only through the policy, always as attachments. */
final class AttachmentController extends Controller
{
    public function store(UploadAttachmentRequest $request, Letter $letter, AddAttachment $add): RedirectResponse
    {
        $this->authorize('addAttachment', $letter);
        $kind = AttachmentKind::tryFrom((string) $request->validated('kind', 'annex')) ?? AttachmentKind::Annex;
        $add->execute($letter, $request->file('file'), $kind === AttachmentKind::Rendered ? AttachmentKind::Annex : $kind, $request->user());

        return back()->with('success', __('letters.flash.attachment_added'));
    }

    public function download(Request $request, LetterAttachment $attachment, LettersAudit $audit): StreamedResponse
    {
        $this->authorize('download', $attachment);
        $audit->record(AuditEventType::LetterAttachmentDownloaded, $request->user(), $attachment, $attachment->letter?->organization_id, ['letter_id' => $attachment->letter_id, 'sha256' => $attachment->sha256]);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
