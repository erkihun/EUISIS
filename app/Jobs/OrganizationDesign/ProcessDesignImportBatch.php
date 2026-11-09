<?php

declare(strict_types=1);

namespace App\Jobs\OrganizationDesign;

use App\Models\DesignImportBatch;
use App\Models\User;
use App\Services\OrganizationDesign\Import\DesignImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Parses and validates one staged import off the request thread. A failure
 * marks the batch failed with the reason; nothing canonical is touched here,
 * so a crash mid-way leaves only staging rows behind.
 */
class ProcessDesignImportBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $batchId, public ?int $actorId) {}

    public function handle(DesignImportService $imports): void
    {
        $batch = DesignImportBatch::query()->find($this->batchId);
        if ($batch === null || in_array($batch->status, [DesignImportBatch::STATUS_CANCELLED, DesignImportBatch::STATUS_COMMITTED], true)) {
            return;
        }
        $actor = $this->actorId ? User::query()->find($this->actorId) : null;

        try {
            $imports->parse($batch, $actor);
        } catch (Throwable $e) {
            $batch->forceFill(['status' => DesignImportBatch::STATUS_FAILED, 'error_summary' => ['exception' => mb_substr($e->getMessage(), 0, 2000)]])->save();
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        DesignImportBatch::query()->whereKey($this->batchId)->whereNotIn('status', [DesignImportBatch::STATUS_COMMITTED])->update(['status' => DesignImportBatch::STATUS_FAILED, 'error_summary' => json_encode(['exception' => mb_substr($e->getMessage(), 0, 2000)])]);
    }
}
