<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Communication\Domain\Enums\ChannelPostingPolicy;
use Modules\Communication\Domain\Enums\ChannelType;

/**
 * An organization-aware channel. Its messages, members and read state live in
 * its conversation stream; this row holds what makes it a channel: where it
 * belongs in the hierarchy and how its membership is kept.
 */
class Channel extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'posting_policy' => ChannelPostingPolicy::class,
            'include_descendants' => 'boolean',
            'auto_membership' => 'boolean',
            'remove_on_transfer' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayName(string $locale): string
    {
        return $locale === 'am' && filled($this->name_am) ? (string) $this->name_am : (string) $this->name;
    }
}
