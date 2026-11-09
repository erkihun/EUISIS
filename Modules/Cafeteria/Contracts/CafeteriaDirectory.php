<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts;

/** The cafeteria locations, for pickers on other modules' pages. */
interface CafeteriaDirectory
{
    /**
     * Every location that is not archived, by English name.
     *
     * @return list<array{id: string, code: ?string, name_en: ?string, name_am: ?string, organization_id: ?string, is_active: bool, location: ?string, organization: ?array{id: string, name_en: ?string}}>
     */
    public function locations(): array;
}
