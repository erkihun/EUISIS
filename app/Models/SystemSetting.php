<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'label_en',
        'label_am',
        'description_en',
        'description_am',
        'options',
        'validation_rules',
        'default_value',
        'is_public',
        'is_encrypted',
        'is_system',
        'is_required',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'bool',
            'is_encrypted' => 'bool',
            'is_system' => 'bool',
            'is_required' => 'bool',
            'options' => 'array',
            'validation_rules' => 'array',
            'sort_order' => 'int',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Decrypt the raw value if encrypted; otherwise return as-is.
     */
    public function decryptedValue(): ?string
    {
        if ($this->value === null || $this->value === '') {
            return $this->value;
        }

        if (! $this->is_encrypted) {
            return $this->value;
        }

        try {
            return Crypt::decryptString($this->value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function typedValue(): mixed
    {
        $raw = $this->decryptedValue();

        return match ($this->type) {
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'integer' => $raw === null || $raw === '' ? null : (int) $raw,
            'json', 'multiselect' => $raw === null || $raw === '' ? [] : json_decode((string) $raw, true),
            default => $raw,
        };
    }

    public function typedDefaultValue(): mixed
    {
        if ($this->default_value === null || $this->default_value === '') {
            return null;
        }

        return match ($this->type) {
            'boolean' => filter_var($this->default_value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->default_value,
            'json', 'multiselect' => json_decode((string) $this->default_value, true),
            default => $this->default_value,
        };
    }

    public function isConfigured(): bool
    {
        return $this->value !== null && $this->value !== '';
    }
}
