<?php

declare(strict_types=1);

namespace App\Actions\Grievances;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\GrievanceResponseStatus;
use App\Models\Grievance;
use App\Models\GrievanceDecisionLetter;
use App\Models\GrievanceResponse;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

readonly class GenerateGrievanceDecisionLetterAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    public function execute(Grievance $grievance, GrievanceResponse $response, User $actor): GrievanceDecisionLetter
    {
        $letterReference = 'LTR-'.$grievance->reference_number.'-'.now()->format('Ymd');

        $pdf = Pdf::loadView('grievances.decision_letter', [
            'grievance' => $grievance->load(['organization', 'category', 'employee']),
            'response' => $response,
            'letterReference' => $letterReference,
            'generatedAt' => now(),
        ]);

        $fileName = 'grievance-letters/'.$grievance->id.'/'.$letterReference.'.pdf';
        Storage::disk('local')->put($fileName, $pdf->output());

        $letter = GrievanceDecisionLetter::query()->updateOrCreate(
            ['grievance_id' => $grievance->id],
            [
                'response_id' => $response->id,
                'letter_reference' => $letterReference,
                'file_path' => $fileName,
                'generated_by_user_id' => $actor->id,
                'generated_at' => now(),
            ],
        );

        $response->update(['status' => GrievanceResponseStatus::Issued]);

        $this->audit->execute(
            eventType: AuditEventType::GrievanceDecisionLetterGenerated,
            actor: $actor,
            auditable: $letter,
            organizationId: $grievance->organization_id,
            newValues: ['letter_reference' => $letterReference],
        );

        return $letter;
    }
}
