<?php

declare(strict_types=1);

namespace App\Enums;

/** Supporting document categories a request may carry. */
enum OrganizationalChangeAttachmentType: string
{
    case ApprovedStructure = 'approved_structure';
    case DecisionLetter = 'decision_letter';
    case StudyDocument = 'study_document';
    case Organogram = 'organogram';
    case SupportingEvidence = 'supporting_evidence';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
