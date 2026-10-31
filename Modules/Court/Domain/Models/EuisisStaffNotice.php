<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff account the EUISIS check suspended or restored, for the administrators' bell. */
class EuisisStaffNotice extends Model
{
    public const SUSPENDED = 'suspended';

    public const RESTORED = 'restored';

    protected $table = 'court_euisis_staff_notices';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
