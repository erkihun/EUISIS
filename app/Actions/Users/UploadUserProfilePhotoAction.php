<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadUserProfilePhotoAction
{
    public function __construct(private readonly WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(User $user, UploadedFile $photo, ?User $actor = null, ?Request $request = null, bool $isProfileUpdate = false): string
    {
        $hadPhoto = $user->profile_photo_path !== null;

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $ext = $photo->getClientOriginalExtension();
        $path = $photo->storeAs(
            "users/profile-photos/{$user->id}",
            Str::uuid().'.'.$ext,
            'public',
        );

        $path = (string) $path;

        $this->writeAuditLogAction->execute(
            $isProfileUpdate ? AuditEventType::ProfilePhotoUpdated : AuditEventType::UserPhotoUpdated,
            $actor ?? $user,
            $user,
            null,
            oldValues: ['profile_photo_configured' => $hadPhoto],
            newValues: ['profile_photo_configured' => true],
            request: $request,
        );

        return $path;
    }
}
