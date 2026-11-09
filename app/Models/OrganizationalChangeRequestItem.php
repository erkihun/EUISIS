<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationalChangeAction;
use App\Enums\OrganizationalChangeEntityType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proposed change inside a request.
 *
 * before_data is a snapshot of the target taken when the request was written,
 * proposed_data is what the requester asks for. Neither is applied to master
 * data until the implementation step runs.
 */
class OrganizationalChangeRequestItem extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organizational_change_request_items';

    protected $fillable = [
        'request_id',
        'entity_type',
        'entity_id',
        'action',
        'before_data',
        'proposed_data',
        'validation_snapshot',
        'resulting_entity_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => OrganizationalChangeEntityType::class,
            'action' => OrganizationalChangeAction::class,
            'before_data' => 'array',
            'proposed_data' => 'array',
            'validation_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OrganizationalChangeRequest::class, 'request_id');
    }

    /** Resolve the live master-data record this item targets, if any. */
    public function resolveEntity(): ?Model
    {
        $class = $this->entity_type->modelClass();

        if ($class === null || $this->entity_id === null) {
            return null;
        }

        return $class::query()->find($this->entity_id);
    }
}
