<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\ClarificationMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a clarification thread. Plain text. */
class ClarificationMessage extends Model
{
    /** @use HasFactory<ClarificationMessageFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_clarification_messages';

    protected $fillable = [
        'clarification_id',
        'author_id',
        'body',
    ];

    protected static function newFactory(): ClarificationMessageFactory
    {
        return ClarificationMessageFactory::new();
    }

    public function clarification(): BelongsTo
    {
        return $this->belongsTo(Clarification::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
