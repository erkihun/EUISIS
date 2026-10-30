<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service point's user (the `service_provider_users` table), published for
 * other modules' relations and pickers. Credentials are hidden; writes are refused.
 */
final class ServicePointUserRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'service_provider_users';

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
            'metadata' => 'array',
        ];
    }

    /** The name stored in polymorphic columns, as for the Entitlements module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\ServiceProviderUser';
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceTypeRecord::class, 'service_type_id');
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServicePointRecord::class, 'service_provider_id');
    }
}
