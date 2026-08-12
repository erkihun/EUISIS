<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdministrativeTribunalCase extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'grievance_id',
        'case_number',
        'status',
        'decision_summary',
        'hearing_date',
        'decision_date',
        'assigned_to_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hearing_date' => 'date',
            'decision_date' => 'date',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'hearing'], true);
    }
}
