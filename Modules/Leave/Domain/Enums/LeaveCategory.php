<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Enums;

/**
 * The legal kind of a leave type. The category, not the type's name, decides
 * which rules run (the sick-leave windows, the maternity periods, the yearly
 * grant), so an institution may rename a type without changing the law.
 */
enum LeaveCategory: string
{
    case Annual = 'annual';
    // The yearly seven days for personal matters (mourning, wedding, examination): never carried forward.
    case Personal = 'personal';
    case Sick = 'sick';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case Unpaid = 'unpaid';
    case Special = 'special';
}
