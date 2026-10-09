<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldWorkType extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'requires_location', 'requires_destination_org', 'requires_attachment', 'requires_completion_note', 'is_active'];

    protected function casts(): array
    {
        return ['requires_location' => 'bool', 'requires_destination_org' => 'bool', 'requires_attachment' => 'bool', 'requires_completion_note' => 'bool', 'is_active' => 'bool'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(FieldWorkRequest::class);
    }
}
