<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

final readonly class PortalLocationSelection
{
    /**
     * @param  list<array{id: string, code: ?string, name_en: ?string, name_am: ?string, location: ?string}>  $locations  active locations, main first
     */
    public function __construct(
        public array $locations,
        public ?string $selectedId,
    ) {}

    public static function none(): self
    {
        return new self([], null);
    }
}
