<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** One switch of the leave module, by key. Read and written through LeaveSettings. */
class LeaveSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
