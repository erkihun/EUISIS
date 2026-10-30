<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Enums;

/** Role of a cafeteria location inside its service network. */
enum CafeteriaLocationType: string
{
    case Main = 'main';
    case Branch = 'branch';
    case ServicePoint = 'service_point';
}
