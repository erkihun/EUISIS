<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** An EUISIS organization mirrored from the EUISIS partner API (EUISIS owns it). */
class EuisisOrganization extends Model
{
    protected $table = 'court_euisis_organizations';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['synced_at' => 'datetime', 'last_sequence' => 'integer'];

    /** The name in the current locale, English when there is no Amharic name. */
    public function displayName(): string
    {
        return app()->getLocale() === 'am' && filled($this->name_am) ? (string) $this->name_am : (string) $this->name_en;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [null, '', 'active'], true);
    }
}
