/** The three public submission types, with the admin label key of each. */
export type SubmissionType = 'feedback' | 'suggestion' | 'complaint';

export const SUBMISSION_TYPES: Array<{ value: SubmissionType; key: string }> = [
    { value: 'feedback', key: 'adminTypeFeedback' },
    { value: 'suggestion', key: 'adminTypeSuggestion' },
    { value: 'complaint', key: 'adminTypeComplaint' },
];
