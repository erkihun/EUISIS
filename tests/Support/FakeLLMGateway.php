<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Intelligence\AI\AiRequest;
use App\Services\Intelligence\AI\AiResult;
use App\Services\Intelligence\AI\Contracts\LLMGateway;

/**
 * A gateway stand-in for feature tests: returns `$result` (swap it between
 * requests — the router memoizes the controller instance, so rebinding the
 * container would not be seen) and keeps every AiRequest it received so a
 * test can assert on what would have left the platform.
 */
final class FakeLLMGateway implements LLMGateway
{
    /** @var list<AiRequest> */
    public array $requests = [];

    public function __construct(public AiResult $result, public bool $isAvailable = true) {}

    public function complete(AiRequest $request): AiResult
    {
        $this->requests[] = $request;

        return $this->result;
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function status(): array
    {
        return ['enabled' => $this->isAvailable, 'provider' => 'fake', 'model' => 'fake-model', 'configured' => $this->isAvailable, 'breaker_open' => false, 'calls_today' => count($this->requests), 'daily_cap' => 500, 'outbound_classes' => ['knowledge_documents']];
    }

    public function lastInput(): string
    {
        return $this->requests === [] ? '' : $this->requests[count($this->requests) - 1]->input;
    }
}
