<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One permission of a Core role (ADR 0009). */
class CoreRolePermission extends Model
{
    public $timestamps = false;

    protected $fillable = ['core_role_id', 'permission'];
}
