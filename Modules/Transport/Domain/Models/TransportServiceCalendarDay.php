<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Providers\Contracts\ReadModels\ProviderRecord;

/** A day the service runs although the calendar says not (operating), or does not run (suspended). */
class TransportServiceCalendarDay extends Model
{
    use HasUuidPrimaryKey;

    public const KIND_OPERATING = 'operating';

    public const KIND_SUSPENDED = 'suspended';

    protected $fillable = ['provider_id', 'transport_route_id', 'calendar_date', 'kind', 'name_en', 'name_am', 'created_by'];

    protected function casts(): array
    {
        return ['calendar_date' => 'date'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderRecord::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }
}
