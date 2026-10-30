<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A response to an information request.
 */
class GrievanceInformationResponse extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_information_responses';

    protected $fillable = [
        'information_request_id',
        'grievance_id',
        'response_text',
        'responded_by',
        'responded_by_employee_id',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(GrievanceInformationRequest::class, 'information_request_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
