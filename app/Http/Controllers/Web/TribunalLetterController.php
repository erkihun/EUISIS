<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeTribunalCase;
use App\Models\CourtMsLetter;
use App\Services\CourtMs\CourtLetterInbox;
use App\Support\Storage\StoredFileChecksum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tribunal cases > Court letters (court-ms Flow 5): the letters the
 * administrative tribunal sent the institutions in the reader's scope.
 */
class TribunalLetterController extends Controller
{
    public function index(Request $request, CourtLetterInbox $inbox): Response
    {
        $user = $request->user();
        abort_unless(CourtLetterInbox::canRead($user), 403);

        $letters = $inbox->visibleTo($user)
            ->with('organization:id,name_en,name_am')
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('opened_at'))
            ->latest('received_at')
            ->paginate(20)
            ->withQueryString();

        // Letters on a case referred from here link to its register entry.
        $tribunalCases = AdministrativeTribunalCase::query()
            ->whereIn('court_case_number', $letters->getCollection()->pluck('case_number')->filter()->unique()->values())
            ->pluck('id', 'court_case_number');

        return Inertia::render('AdministrativeTribunal/CourtLetters', [
            'letters' => $letters->through(fn (CourtMsLetter $letter): array => [
                'id' => (string) $letter->getKey(),
                'case_number' => $letter->case_number,
                'case_title' => $letter->case_title,
                'subject' => $letter->subject,
                'reference_number' => $letter->reference_number,
                'approved_by' => $letter->approved_by,
                'approved_at' => $letter->approved_at?->toIso8601String(),
                'received_at' => $letter->received_at?->toIso8601String(),
                'opened' => $letter->opened_at !== null,
                'organization' => $letter->organization ? ['name_en' => $letter->organization->name_en, 'name_am' => $letter->organization->name_am] : null,
                'tribunal_case_id' => $tribunalCases[$letter->case_number] ?? null,
            ]),
            'filters' => ['unread' => $request->boolean('unread')],
        ]);
    }

    public function download(Request $request, CourtLetterInbox $inbox, string $letterId): StreamedResponse
    {
        $user = $request->user();
        abort_unless(CourtLetterInbox::canRead($user), 403);

        $letter = $inbox->visibleTo($user)->whereKey($letterId)->firstOrFail();
        $disk = Storage::disk($letter->pdf_disk);
        abort_unless($disk->exists($letter->pdf_path), 404);
        if (! StoredFileChecksum::matches($letter->pdf_disk, $letter->pdf_path, $letter->pdf_sha256)) {
            report(new \RuntimeException('Court letter integrity check failed: '.$letter->getKey()));
            abort(409);
        }

        if ($letter->opened_at === null) {
            $letter->forceFill(['opened_at' => now(), 'opened_by_user_id' => $user->getKey()])->save();
        }

        return $disk->download($letter->pdf_path, preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($letter->reference_number ?: $letter->case_number ?: 'court-letter')).'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
