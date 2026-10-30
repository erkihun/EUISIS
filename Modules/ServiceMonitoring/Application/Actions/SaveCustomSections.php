<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Saves the answers to the additional sections of the report's form template.
 * Partial input is kept (autosave); required answers are enforced before
 * submission (SubmissionValidator). Totals and row sums are recomputed here.
 */
final readonly class SaveCustomSections
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(Submission $submission, array $data, User $actor): void
    {
        $this->guard->assertWritable($submission);
        $content = $submission->templateContent();
        if (! $content->hasCustomSections()) {
            throw ValidationException::withMessages(['custom' => __('service-monitoring.form_templates.errors.no_custom_sections')]);
        }

        $result = $content->custom()->normalize($data, complete: false);
        if ($result['errors'] !== []) {
            throw ValidationException::withMessages(self::keyed($result['errors']));
        }

        DB::transaction(function () use ($submission, $result, $actor): void {
            $submission->forceFill(['custom_data' => $result['data']])->save();
            $this->audit->record(AuditEventType::ServiceMonitoringSectionSaved, $actor, $submission, $submission->organization_id, [
                'section' => 'custom', 'blocks' => array_keys($result['data']),
            ]);
        });
    }

    /**
     * FormSchema reports `data.<block>…`; the entry screen reads `custom.<block>…`.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    public static function keyed(array $errors): array
    {
        $keyed = [];
        foreach ($errors as $path => $message) {
            $keyed['custom.'.substr($path, strlen('data.'))] = $message;
        }

        return $keyed;
    }
}
