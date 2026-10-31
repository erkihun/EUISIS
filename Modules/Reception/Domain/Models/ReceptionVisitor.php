<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReceptionVisitor extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['name', 'phone', 'phone_hash', 'category', 'employee_id'];

    protected $hidden = ['phone', 'phone_hash'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ReceptionVisit::class, 'visitor_id');
    }
}
