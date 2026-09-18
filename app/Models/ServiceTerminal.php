<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTerminal extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    /**
     * The certificate pointer identifies key material held elsewhere; only a
     * fingerprint derived from it is ever safe to surface.
     */
    protected $hidden = ['certificate_reference'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'provider_id');
    }

    public function cafeteriaProvider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function externalApplication(): BelongsTo
    {
        return $this->belongsTo(ExternalApplication::class, 'external_application_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Short, non-reversible label for the configured certificate. Lets an admin
     * confirm which credential a terminal uses without exposing the pointer.
     */
    public function certificateFingerprint(): ?string
    {
        $reference = $this->getAttribute('certificate_reference');

        return $reference ? strtoupper(substr(hash('sha256', (string) $reference), 0, 16)) : null;
    }
}
