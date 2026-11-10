<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\RegistrationStatus;

/**
 * One institution's registry entry for a letter: its own number, direction and
 * handling status. The sender's entry is numbered at dispatch (or at
 * registration for internal letters), the receiver's when its registrar
 * receives the letter.
 */
class LetterRegistration extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letter_registrations';

    protected $fillable = [
        'letter_id', 'organization_id', 'direction', 'registry_number', 'sequence_number', 'period_key', 'status',
        'registered_at', 'registered_by', 'received_on', 'due_on', 'physical_location', 'acknowledged_at', 'acknowledgement_note', 'reminded_at',
        'escalated_at', 'interim_reminded_at', 'closed_at', 'closed_by', 'closing_note',
    ];

    protected function casts(): array
    {
        return [
            'direction' => LetterDirection::class,
            'status' => RegistrationStatus::class,
            'registered_at' => 'datetime',
            'received_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'acknowledged_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'interim_reminded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function routings(): HasMany
    {
        return $this->hasMany(LetterRouting::class, 'registration_id')->orderBy('step');
    }

    public function isNumbered(): bool
    {
        return $this->registry_number !== null;
    }
}
