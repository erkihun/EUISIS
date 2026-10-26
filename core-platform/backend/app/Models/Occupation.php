<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsJobMaster;
use Illuminate\Database\Eloquent\Model;

/** An occupation (the kind of work): job master data (ADR 0010). */
class Occupation extends Model
{
    use IsJobMaster;

    public const POSITION_COLUMN = 'occupation_id';

    protected $table = 'occupations';
}
