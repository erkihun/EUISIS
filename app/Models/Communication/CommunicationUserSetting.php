<?php

declare(strict_types=1);

namespace App\Models\Communication;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's communication preferences and presence heartbeat. */
class CommunicationUserSetting extends Model
{
    use HasUuidPrimaryKey;

    public const PREFERENCES = [
        'allow_direct_messages', 'show_online_status', 'show_last_seen', 'desktop_notifications',
        'sound_notifications', 'email_announcements', 'sms_emergency_announcements',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'allow_direct_messages' => 'boolean',
            'show_online_status' => 'boolean',
            'show_last_seen' => 'boolean',
            'desktop_notifications' => 'boolean',
            'sound_notifications' => 'boolean',
            'email_announcements' => 'boolean',
            'sms_emergency_announcements' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** The stored settings, or the defaults (not saved) when the person has none yet. */
    public static function for(User $user): self
    {
        return self::query()->where('user_id', $user->getKey())->first()
            ?? (new self)->forceFill(['user_id' => $user->getKey(), ...array_fill_keys(self::PREFERENCES, true)]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
