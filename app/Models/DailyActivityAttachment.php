<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Supporting evidence, stored on the private `local` disk. */
class DailyActivityAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'daily_activity_item_id',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(DailyActivityLog::class, 'daily_activity_log_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
