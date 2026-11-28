<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** The timeline of a request: who did what, when, and what they wrote. Append-only. */
class LeaveRequestEvent extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['leave_request_id', 'action', 'actor_user_id', 'actor_name', 'comment', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
