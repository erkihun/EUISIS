<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts\Data;

final readonly class CafeteriaPortalSelection
{
    /**
     * @param  list<array{id: string, code: ?string, name_en: ?string, name_am: ?string, location: ?string}>  $locations  active locations, main first
     */
    public function __construct(
        public array $locations,
        public ?string $selectedId,
    ) {}
}
