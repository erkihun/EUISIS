<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsJobMaster;
use Illuminate\Database\Eloquent\Model;

/** A job grade (pay band): job master data (ADR 0010). */
class JobGrade extends Model
{
    use IsJobMaster;

    public const POSITION_COLUMN = 'job_grade_id';

    protected $table = 'job_grades';
}
