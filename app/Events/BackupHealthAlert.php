<?php

namespace App\Events;

class BackupHealthAlert
{
    public function __construct(public string $state, public array $issues) {}
}
