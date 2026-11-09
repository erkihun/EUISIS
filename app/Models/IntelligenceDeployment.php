<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recorded release (brief §44, §107): what was deployed, when, where.
 * Recorded by `intelligence:record-deployment` from the release script;
 * without it there is nothing to correlate and nothing is guessed.
 */
class IntelligenceDeployment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['release', 'commit_hash', 'environment', 'deployed_at', 'change_reference', 'recorded_by', 'notes'];

    protected function casts(): array
    {
        return ['deployed_at' => 'datetime'];
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(IntelligenceIncident::class, 'deployment_id');
    }
}
