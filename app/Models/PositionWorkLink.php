<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A position's stable responsibility for a service, process, activity or task of its organization (brief §67–68). */
class PositionWorkLink extends Model
{
    use HasUuidPrimaryKey;

    public const OBJECT_TYPES = ['service', 'process', 'activity', 'task'];

    /** @var array<string, class-string<Model>> */
    public const OBJECT_MODELS = ['service' => Service::class, 'process' => Process::class, 'activity' => ProcessActivity::class, 'task' => ProcessTask::class];

    protected $fillable = ['position_id', 'organization_id', 'object_type', 'object_id', 'is_primary', 'sequence', 'created_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'sequence' => 'integer'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function object(): ?Model
    {
        $model = self::OBJECT_MODELS[$this->object_type] ?? null;

        return $model === null ? null : $model::query()->find($this->object_id);
    }
}
