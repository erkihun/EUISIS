<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\UploadAttachmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\UploadAttachmentRequest;
use App\Http\Resources\Communication\MessageResource;
use App\Models\Communication\Conversation;
use App\Models\Communication\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload and controlled download. Files are never public: the download
 * re-checks membership and scan state, streams from the private disk, and is
 * served as a download (images inline for preview) with nosniff and a sandbox
 * so nothing a file contains can run in the page.
 */
class AttachmentController extends Controller
{
    public function store(UploadAttachmentRequest $request, Conversation $conversation, UploadAttachmentAction $upload): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $conversation), 404);
        $attachment = $upload->execute($conversation, $request->user(), $request->file('file'));

        return response()->json(['data' => MessageResource::attachment($attachment)], 201);
    }

    public function download(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('download', $attachment), 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $inline = $request->boolean('inline') && $attachment->isImage();

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, MessageAttachment $attachment): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('delete', $attachment), 404);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(['deleted' => true]);
    }
}
