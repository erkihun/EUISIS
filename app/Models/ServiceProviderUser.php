<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class ServiceProviderUser extends Authenticatable
{
    use HasUuidPrimaryKey;
    use Notifiable;

    protected $fillable = [
        'service_type_id',
        'service_provider_id',
        'name',
        'email',
        'username',
        'phone_number',
        'password',
        'status',
        'portal_enabled',
        'must_change_password',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'created_by',
        'updated_by',
        'suspended_by',
        'suspended_at',
        'suspension_reason',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'phone_number',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'portal_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'metadata' => 'array',
        ];
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }
}
