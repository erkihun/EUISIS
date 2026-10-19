<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The scanned instrument behind a legal source. Lives on the private disk;
 * the browser only ever receives it through the controlled download route.
 */
class LegalSourceAttachment extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['legal_source_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'label', 'uploaded_by', 'created_at'];

    /** The storage location is never serialised to a page. */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LegalSource::class, 'legal_source_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
