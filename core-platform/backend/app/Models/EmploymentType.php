<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsJobMaster;
use Illuminate\Database\Eloquent\Model;

/** An employment type (permanent, contract, ...): job master data (ADR 0010). */
class EmploymentType extends Model
{
    use IsJobMaster;

    public const POSITION_COLUMN = 'employment_type_id';

    protected $table = 'employment_types';
}
