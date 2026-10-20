<?php

declare(strict_types=1);

/*
 * Addis One Intelligence — Knowledge Assistant dictionary (merged under the
 * `intelligence.` prefix by IntelligenceLabels, so a page reads
 * `intelligence.classifications.internal`). Mirrored key-for-key in lang/am.
 */
return [
    'classifications' => [
        'public' => 'Public',
        'internal' => 'Internal',
        'confidential' => 'Confidential',
        'restricted' => 'Restricted',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'retired' => 'Retired',
    ],

    'modes' => [
        'ai' => 'Synthesised answer',
        'extractive' => 'Quoted passages',
        'none' => 'Assistant off',
    ],

    'source_types' => [
        'manual' => 'Entered by hand',
        'repo_doc' => 'Repository document',
        'upload' => 'Uploaded file',
    ],

    'notices' => [
        'disabled' => 'The Knowledge Assistant is switched off in System Settings.',
        'no_passages' => 'No approved passage matches this question.',
        'ai_off' => 'AI synthesis is off or knowledge documents may not be sent outbound: the matching passages are quoted as they are.',
        'unavailable' => 'The AI provider was unavailable: the matching passages are quoted as they are.',
        'refused' => 'The AI request was refused by the gateway policy: the matching passages are quoted as they are.',
        'failed' => 'The AI provider failed: the matching passages are quoted as they are.',
        'invalid' => 'The AI answer did not pass validation: the matching passages are quoted as they are.',
    ],

    'flash' => [
        'document_created' => 'Knowledge document saved as a draft.',
        'document_updated' => 'Knowledge document updated.',
        'document_approved' => 'Knowledge document approved.',
        'document_retired' => 'Knowledge document retired.',
        'document_deleted' => 'Knowledge document deleted.',
    ],

    'validation' => [
        'organization_out_of_scope' => 'That organization is outside your scope.',
        'unknown_permission' => 'That permission is not in the catalogue.',
    ],
];
