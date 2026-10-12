<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeServiceFeedbackAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_service_feedback_id', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'sha256', 'scan_status',
    ];

    protected $hidden = ['disk', 'path'];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'employee_service_feedback_id');
    }
}
