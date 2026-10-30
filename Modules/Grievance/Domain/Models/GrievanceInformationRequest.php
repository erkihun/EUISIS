<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceInformationRequestStatus;
use App\Enums\Grievance\GrievanceInformationTarget;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A handler's request for further information. Responses and their files attach to the request only.
 */
class GrievanceInformationRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_information_requests';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'requested_from_type',
        'requested_from_id',
        'requested_from_name',
        'request_text',
        'due_at',
        'status',
        'pauses_sla',
        'sla_pause_id',
        'requested_by',
        'requested_at',
        'responded_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_from_type' => GrievanceInformationTarget::class,
            'status' => GrievanceInformationRequestStatus::class,
            'due_at' => 'datetime',
            'pauses_sla' => 'bool',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(GrievanceInformationResponse::class, 'information_request_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(GrievanceEvidence::class, 'information_request_id');
    }
}
