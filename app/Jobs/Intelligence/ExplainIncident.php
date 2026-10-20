<?php

declare(strict_types=1);

namespace App\Jobs\Intelligence;

use App\Models\IntelligenceIncident;
use App\Models\User;
use App\Services\Intelligence\AI\RootCauseAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Asks the root-cause assistant about one incident on the long queue; the page polls the incident for the outcome. */
final class ExplainIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly string $incidentId,
        public readonly ?int $userId,
        public readonly string $locale,
    ) {
        $this->onQueue('long');
    }

    public function handle(RootCauseAssistant $assistant): void
    {
        $incident = IntelligenceIncident::query()->find($this->incidentId);
        if ($incident === null) {
            return;
        }
        $actor = $this->userId !== null ? User::query()->find($this->userId) : null;
        $assistant->explain($incident, $actor, $this->locale);
    }
}
