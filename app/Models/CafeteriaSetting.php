<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaSetting extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'key',
        'value',
        'value_type',
        'group',
        'label_en',
        'label_am',
        'description_en',
        'description_am',
        'is_public',
        'is_encrypted',
        'sort_order',
        'updated_by',
    ];

    protected $casts = [
        'value'        => 'json',
        'is_public'    => 'boolean',
        'is_encrypted' => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Typed accessor for simple scalar values. */
    public function getValue(): mixed
    {
        return $this->value;
    }
}
