<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\VisitMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A team member of a monitoring visit. */
class VisitMember extends Model
{
    /** @use HasFactory<VisitMemberFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_visit_members';

    protected $fillable = [
        'visit_id',
        'user_id',
    ];

    protected static function newFactory(): VisitMemberFactory
    {
        return VisitMemberFactory::new();
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(MonitoringVisit::class, 'visit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
