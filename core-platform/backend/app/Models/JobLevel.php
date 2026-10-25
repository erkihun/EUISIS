<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsJobMaster;
use Illuminate\Database\Eloquent\Model;

/** A job level (seniority step): job master data (ADR 0010). */
class JobLevel extends Model
{
    use IsJobMaster;

    public const POSITION_COLUMN = 'job_level_id';

    protected $table = 'job_levels';
}
