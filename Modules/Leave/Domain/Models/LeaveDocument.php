<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence attached to a request (a medical certificate, a court summons).
 * The file sits on the private disk and is served only through the module's
 * download action, which re-checks who is asking.
 */
class LeaveDocument extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['leave_request_id', 'kind', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
