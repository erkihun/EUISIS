<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One previous password hash of an account. Sensitive authentication data:
 * the hash is hidden from every serialization, and nothing exposes this
 * model through a route, resource, export or audit payload.
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['authenticatable_type', 'authenticatable_id', 'password_hash'];

    protected $hidden = ['password_hash'];
}
