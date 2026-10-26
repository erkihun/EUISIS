<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsJobMaster;
use Illuminate\Database\Eloquent\Model;

/** A profession (the qualification): job master data (ADR 0010). */
class Profession extends Model
{
    use IsJobMaster;

    public const POSITION_COLUMN = 'profession_id';

    protected $table = 'professions';
}
