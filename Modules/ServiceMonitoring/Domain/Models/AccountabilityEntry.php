<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Casts\EncryptedOrPlaintext;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use Database\Factories\ServiceMonitoring\AccountabilityEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ServiceMonitoring\Domain\Enums\EntrySource;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\ReasonCategory;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Enums\SubjectType;

/** Section I.1: one person (or customer) held accountable, with the final action taken (D12, D13). Name, reason and action detail are encrypted at rest. */
class AccountabilityEntry extends Model
{
    /** @use HasFactory<AccountabilityEntryFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_accountability_entries';

    protected $fillable = [
        'submission_id',
        'sequence',
        'subject_type',
        'employee_id',
        'full_name',
        'office_name',
        'role_category',
        'reason_category',
        'reason_detail',
        'final_action',
        'action_detail',
        'action_date',
        'remark',
        'source',
        'source_decision_id',
        'repeat_confirmed',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'subject_type' => SubjectType::class,
            'role_category' => RoleCategory::class,
            'reason_category' => ReasonCategory::class,
            'final_action' => FinalAction::class,
            'source' => EntrySource::class,
            'action_date' => 'date:Y-m-d',
            'repeat_confirmed' => 'boolean',
            'full_name' => EncryptedOrPlaintext::class,
            'reason_detail' => EncryptedOrPlaintext::class,
            'action_detail' => EncryptedOrPlaintext::class,
        ];
    }

    protected static function newFactory(): AccountabilityEntryFactory
    {
        return AccountabilityEntryFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
