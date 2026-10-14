<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

/**
 * Request-scoped memo for figures several panels share (the published
 * hierarchy version, the roll-up map, capacity, headcount). Bound as a scoped
 * singleton and flushed whenever a request builds its SituationContext, so
 * nothing outlives the request that computed it. Keys always include the
 * scope (and, for figures, the filter) fingerprint.
 */
final class SituationMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    public function remember(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $compute();
        }

        return $this->values[$key];
    }

    /** Start a new request's computation: nothing computed for an earlier request is reused. */
    public function flush(): void
    {
        $this->values = [];
    }

    public static function key(SituationContext $context, string $name): string
    {
        return $name.'|'.$context->scope->fingerprint().'|'.$context->filters->fingerprint();
    }
}
