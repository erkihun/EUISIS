<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's in-app notification (ADR 0011): an event key and its
 * parameters, written in the reader's language when shown.
 */
class CoreNotification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return array{title: string, body: string} */
    public function text(string $locale): array
    {
        $params = (array) ($this->params ?? []);

        return [
            'title' => (string) __("notifications.events.{$this->event}.title", $params, $locale),
            'body' => (string) __("notifications.events.{$this->event}.body", $params, $locale),
        ];
    }
}
