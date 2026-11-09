<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\MonitoringAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file on the private disk: a submission's stamped copy or an action item's evidence. Served only through the authorized download route. */
class MonitoringAttachment extends Model
{
    /** @use HasFactory<MonitoringAttachmentFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_attachments';

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'sha256',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function newFactory(): MonitoringAttachmentFactory
    {
        return MonitoringAttachmentFactory::new();
    }

    public const TYPE_SUBMISSION = 'submission';

    public const TYPE_ACTION_ITEM = 'action_item';

    public const KIND_STAMPED_COPY = 'stamped_copy';

    public const KIND_EVIDENCE = 'evidence';

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
