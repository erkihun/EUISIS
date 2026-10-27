<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsJobMaster;
use Illuminate\Database\Eloquent\Model;

/** A position category: job master data (ADR 0010). */
class PositionCategory extends Model
{
    use IsJobMaster;

    public const POSITION_COLUMN = 'position_category_id';

    protected $table = 'position_categories';
}
