<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceParticipantRole;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hearing participant. Contact details are encrypted at rest and shown only to the case handlers.
 */
class GrievanceHearingParticipant extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_hearing_participants';

    protected $fillable = [
        'hearing_id',
        'role',
        'employee_id',
        'name',
        'affiliation',
        'contact',
        'attendance',
        'notice_sent_at',
        'notice_channel',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => GrievanceParticipantRole::class,
            'contact' => 'encrypted',
            'notice_sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(GrievanceHearing::class, 'hearing_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
