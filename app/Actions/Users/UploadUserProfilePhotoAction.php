<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Support\Images\ImageKind;
use App\Support\Images\ImageUploads;
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

        // Shrunk and re-encoded for the web; the name comes from the detected
        // content, never the uploader's file name (SEC-10).
        $path = ImageUploads::store($photo, ImageKind::Photo, "users/profile-photos/{$user->id}", 'public', (string) Str::uuid());

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
