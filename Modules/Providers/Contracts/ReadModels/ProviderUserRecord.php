<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\ReadModels;

use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A provider-portal user record, published for other modules' relations
 * (who submitted an invoice, who scanned a boarding). It serializes as the
 * Providers module's own model does, credentials hidden; writes are refused.
 */
final class ProviderUserRecord extends ReadOnlyModel
{
    use SoftDeletes;

    protected $table = 'provider_users';

    protected $keyType = 'string';

    public $incrementing = false;

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

    /** The name stored in polymorphic columns, as for the Providers module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\ProviderUser';
    }
}
