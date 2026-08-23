<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CafeteriaSetting extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_settings';

    protected $fillable = [
        'provider_id',
        'key',
        'value',
        'type',
        'group',
        'label',
    ];
}
