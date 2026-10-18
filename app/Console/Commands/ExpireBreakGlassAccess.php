<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\AccessRequestStatus;
use App\Models\Communication\CommunicationAccessRequest;
use Illuminate\Console\Command;

/**
 * Marks investigation access past its end as expired. Access stops at
 * expires_at regardless (every read checks it); this keeps statuses and the
 * audit trail honest.
 */
class ExpireBreakGlassAccess extends Command
{
    protected $signature = 'communication:expire-investigations';

    protected $description = 'Mark Communication investigation (break-glass) access past its end as expired';

    public function handle(WriteAuditLogAction $audit): int
    {
        $count = 0;
        CommunicationAccessRequest::query()
            ->whereIn('status', [AccessRequestStatus::Approved->value, AccessRequestStatus::Active->value])
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($requests) use ($audit, &$count): void {
                foreach ($requests as $request) {
                    $request->forceFill(['status' => AccessRequestStatus::Expired])->save();
                    $audit->execute(AuditEventType::CommunicationAccessRevoked, null, $request, null, null, ['status' => 'EXPIRED']);
                    $count++;
                }
            });

        $this->info("Expired {$count} investigation access grant(s).");

        return self::SUCCESS;
    }
}
