<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Modules\Feedback\Contracts\PublicSubmissionTypes;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\PublicComplaints\Domain\Models\PublicComplaint;
use Modules\ServiceFeedback\Domain\Models\ServiceFeedbackSubmission;
use Modules\TipOffs\Domain\Models\TipOff;
use Tests\Support\PublicFeedbackScenario;

/*
 * ADR 0032: Service feedback, Tip-offs and Public complaints are modules that
 * each own their table; Feedback (the intake and case handling) writes through
 * their registration and reads the three through a view.
 */

beforeEach(function (): void {
    $this->employee = PublicFeedbackScenario::employeeTarget('MOD');
    RateLimiter::clear('sf-submit:'.$this->employee->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
    $this->base = '/service-feedback/'.$this->employee->token->token;
});

it('stores each type in the table its own module owns, read together through the view', function (): void {
    $this->post($this->base.'/feedback', pfComplete('feedback', ['rating' => 4, 'position_service_id' => $this->employee->positionService->id]))->assertSessionHasNoErrors();
    $this->post($this->base.'/suggestion', ['message' => 'Tip.', 'tip_type' => 'CORRUPTION'])->assertSessionHasNoErrors();
    $this->post($this->base.'/complaint', pfComplete('complaint', ['message' => 'Complaint.']))->assertSessionHasNoErrors();

    expect(ServiceFeedbackSubmission::query()->count())->toBe(1)
        ->and(TipOff::query()->count())->toBe(1)
        ->and(PublicComplaint::query()->count())->toBe(1)
        ->and(EmployeeServiceFeedback::query()->pluck('type')->map->value->sort()->values()->all())->toBe(['complaint', 'feedback', 'suggestion'])
        ->and(Schema::hasView('employee_service_feedback'))->toBeTrue()
        ->and(Schema::hasTable('employee_service_feedback_legacy'))->toBeFalse();

    // A case-handling update reaches the type's table.
    $complaint = EmployeeServiceFeedback::query()->where('type', 'complaint')->sole();
    $complaint->forceFill(['review_note' => 'Checked.'])->save();
    expect(PublicComplaint::query()->sole()->review_note)->toBe('Checked.');

    // Deleting a submission takes its case records with it (the view cannot cascade).
    $tip = EmployeeServiceFeedback::query()->where('type', 'suggestion')->sole();
    expect($tip->statusHistory()->count())->toBeGreaterThan(0);
    $tip->delete();
    expect(TipOff::query()->count())->toBe(0)
        ->and(DB::table('public_feedback_status_history')->where('submission_id', $tip->id)->count())->toBe(0);
});

it('lets each module define its type, and keeps the intake free of the type modules', function (): void {
    $types = app(PublicSubmissionTypes::class);
    expect(collect($types->all())->map(fn ($type) => [$type->type()->value, $type->table(), $type->tracking()])->sortBy(0)->values()->all())->toBe([
        ['complaint', 'public_complaints', 'reference_and_phone'],
        ['feedback', 'public_service_feedback', null],
        ['suggestion', 'public_tip_offs', 'reference'],
    ]);

    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules/Feedback'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match('/Modules\\\\(ServiceFeedback|TipOffs|PublicComplaints)\\\\/', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getFilename();
        }
    }
    expect($offenders)->toBe([]);
});
