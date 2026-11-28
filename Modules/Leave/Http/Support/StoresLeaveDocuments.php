<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\Leave\Application\Services\LeaveRequestService;
use Modules\Leave\Domain\Models\LeaveRequest;

/** Puts uploaded evidence on the private disk and records it on the request. */
trait StoresLeaveDocuments
{
    /** @param array<int, UploadedFile>|null $files */
    protected function storeDocuments(LeaveRequestService $service, User $actor, LeaveRequest $leaveRequest, ?array $files): void
    {
        foreach ($files ?? [] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = $file->store("leave/{$leaveRequest->id}", 'local');
            if ($path !== false) {
                $service->addDocument($actor, $leaveRequest, $path, $file->getClientOriginalName(), $file->getMimeType(), (int) $file->getSize());
            }
        }
    }
}
