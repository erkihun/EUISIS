<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReceptionCounter extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['reception_desk_id', 'code', 'name_en', 'name_am', 'status', 'assigned_user_id'];

    public function desk(): BelongsTo
    {
        return $this->belongsTo(ReceptionDesk::class, 'reception_desk_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
