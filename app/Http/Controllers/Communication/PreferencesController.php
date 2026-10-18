<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\UpdatePreferencesRequest;
use App\Models\Communication\CommunicationBlock;
use App\Models\Communication\CommunicationUserSetting;
use App\Services\Communication\CommunicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Someone's own Communication preferences: who may message them, what others
 * see of their presence, how announcements reach them, and who they blocked.
 * Options the system has switched off for everyone are shown as unavailable.
 */
class PreferencesController extends Controller
{
    public function index(Request $request, CommunicationSettings $settings): Response
    {
        $user = $request->user();
        $mine = CommunicationUserSetting::for($user);

        return Inertia::render('Communication/Preferences', [
            'preferences' => [
                'allow_direct_messages' => (bool) $mine->allow_direct_messages,
                'show_online_status' => (bool) $mine->show_online_status,
                'show_last_seen' => (bool) $mine->show_last_seen,
                'email_announcements' => (bool) $mine->email_announcements,
                'sms_emergency_announcements' => (bool) $mine->sms_emergency_announcements,
            ],
            'system' => [
                'presence' => $settings->allowPresence(),
                'lastSeen' => $settings->allowLastSeen(),
                'emailAnnouncements' => $settings->emailUrgentAnnouncements(),
                'smsAnnouncements' => $settings->smsEmergencyAnnouncements(),
            ],
            'blocked' => CommunicationBlock::query()->where('blocker_id', $user->getKey())
                ->join('users', 'users.id', '=', 'communication_blocks.blocked_id')->orderBy('users.name')
                ->get(['users.id', 'users.name'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->values(),
        ]);
    }

    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $setting = CommunicationUserSetting::query()->where('user_id', $user->getKey())->first()
            ?? (new CommunicationUserSetting)->forceFill(['user_id' => $user->getKey()]);
        $setting->forceFill(array_map(fn ($v) => (bool) $v, $request->validated()))->save();

        return back()->with('success', __('communication.flash.preferences_saved'));
    }
}
