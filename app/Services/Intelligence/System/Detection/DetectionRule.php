<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection;

/**
 * One deterministic detector (brief §30): reads signals through the window,
 * compares them with a configured limit or a robust baseline, and returns
 * findings. Rules never read logs, never call a model, and return nothing
 * when the signal they need is not collected.
 */
interface DetectionRule
{
    /** Stable key, used in incident fingerprints and the dictionary (`intelligence.rules.<key>`). */
    public function key(): string;

    /** @return list<Finding> */
    public function evaluate(DetectionWindow $window): array;
}
