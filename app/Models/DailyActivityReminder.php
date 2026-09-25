<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Idempotency record: one row per reminder actually sent. */
class DailyActivityReminder extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['employee_id', 'activity_date', 'reminder_type', 'sent_at'];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
        ];
    }
}
