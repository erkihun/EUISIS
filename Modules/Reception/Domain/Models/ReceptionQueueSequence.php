<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

final class ReceptionQueueSequence extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['reception_desk_id', 'queue_date', 'last_number'];

    protected function casts(): array
    {
        return ['queue_date' => 'date:Y-m-d', 'last_number' => 'integer'];
    }
}
