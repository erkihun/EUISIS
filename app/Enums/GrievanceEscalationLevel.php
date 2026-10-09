<?php

declare(strict_types=1);

namespace App\Enums;

enum GrievanceEscalationLevel: string
{
    case Committee = 'committee';
    case AdministrativeTribunal = 'administrative_tribunal';
}
