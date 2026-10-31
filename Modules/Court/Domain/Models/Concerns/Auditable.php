<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the old and new values of a court record's changes in `court_audits`,
 * in the shape owen-it/laravel-auditing wrote them in court-ms (that package is
 * not part of EUISIS). Secrets listed in `$hidden` are left out.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => self::writeAudit($model, 'created', [], $model->getAttributes()));
        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            self::writeAudit($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
        });
        static::deleted(fn (Model $model) => self::writeAudit($model, 'deleted', $model->getOriginal(), []));
    }

    private static function writeAudit(Model $model, string $event, array $old, array $new): void
    {
        $hidden = array_flip(array_merge($model->getHidden(), ['password', 'remember_token', 'mail_password', 'telegram_bot_token', 'sms_api_key', 'sms_api_secret']));
        $user = Auth::user();
        $request = app()->bound('request') ? request() : null;

        DB::table('court_audits')->insert([
            'user_type' => $user?->getMorphClass(),
            'user_id' => $user?->getAuthIdentifier(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => json_encode(array_diff_key($old, $hidden), JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode(array_diff_key($new, $hidden), JSON_UNESCAPED_UNICODE),
            'url' => $request && ! app()->runningInConsole() ? $request->fullUrl() : 'console',
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 1023) : null,
            'tags' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
