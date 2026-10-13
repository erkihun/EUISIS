<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit (or a proposed unit) holds one responsibility type for one object:
 * a function, service, process or activity (brief §37).
 */
class UnitResponsibility extends Model
{
    use HasUuidPrimaryKey;

    public const OBJECT_TYPES = ['function', 'service', 'process', 'activity'];

    /** @var array<string, class-string<Model>> */
    public const OBJECT_MODELS = [
        'function' => OrganizationFunction::class,
        'service' => Service::class,
        'process' => Process::class,
        'activity' => ProcessActivity::class,
    ];

    protected $fillable = ['organization_id', 'organization_unit_id', 'unit_design_proposal_id', 'object_type', 'object_id', 'responsibility_type_id', 'notes', 'sequence', 'created_by'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(UnitDesignProposal::class, 'unit_design_proposal_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityType::class, 'responsibility_type_id');
    }

    public function object(): ?Model
    {
        $model = self::OBJECT_MODELS[$this->object_type] ?? null;

        return $model === null ? null : $model::query()->find($this->object_id);
    }
}
