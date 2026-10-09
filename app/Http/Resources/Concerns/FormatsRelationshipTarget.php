<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

trait FormatsRelationshipTarget
{
    protected function targetPayload(Model|null $target): ?array
    {
        if ($target === null) {
            return null;
        }

        return [
            'id' => $target->getKey(),
            'name_en' => $target->name_en ?? $target->office_code ?? $target->code ?? null,
            'name_am' => $target->name_am ?? null,
            'code' => $target->code ?? $target->office_code ?? null,
        ];
    }
}
