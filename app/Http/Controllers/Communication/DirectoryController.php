<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\CommunicationUserSetting;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Services\Communication\CommunicationDirectory;
use App\Services\Communication\CommunicationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Employee search within the configured reach, profile photos, and the presence heartbeat. */
class DirectoryController extends Controller
{
    public function __construct(
        private readonly CommunicationDirectory $directory,
        private readonly CommunicationSettings $settings,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        return response()->json(['data' => $this->directory->search($request->user(), $term, app()->getLocale())]);
    }

    /** A photo is shown to people who can reach the person or already share a conversation with them. */
    public function photo(Request $request, User $user): Response
    {
        $viewer = $request->user();
        $shares = ConversationMember::query()->where('user_id', $user->getKey())->whereNull('left_at')
            ->whereIn('conversation_id', ConversationMember::query()->select('conversation_id')->where('user_id', $viewer->getKey())->whereNull('left_at'))
            ->exists();
        abort_unless($viewer->is($user) || $shares || $this->directory->canReach($viewer, $user), 404);
        $path = $user->employee?->photo_path;
        abort_unless(filled($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'photo', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=600']);
    }

    /** Presence heartbeat: a timestamp only, throttled; nothing about activity is stored. */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($this->settings->allowPresence() || $this->settings->allowLastSeen()) {
            $setting = CommunicationUserSetting::query()->where('user_id', $request->user()->getKey())->first()
                ?? (new CommunicationUserSetting)->forceFill(['user_id' => $request->user()->getKey()]);
            $setting->forceFill(['last_seen_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }
}
