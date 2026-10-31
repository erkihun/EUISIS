<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A PDF that came from EUISIS, kept as a case's evidence document (as an applicant's evidence PDF). */
final class CaseDocuments
{
    public function store(int $caseId, UploadedFile $file, string $title, string $source): void
    {
        $stored = app(SecureUploadService::class)->store($file, 'evidences', 'court_private', [
            'related_type' => 'court_case',
            'related_id' => $caseId,
        ]);

        $insert = ['case_id' => $caseId, 'type' => 'document', 'created_at' => now(), 'updated_at' => now()];
        if (Schema::hasColumn('court_case_evidences', 'file_path')) {
            $insert['file_path'] = $stored;
        } elseif (Schema::hasColumn('court_case_evidences', 'path')) {
            $insert['path'] = $stored;
        }
        if (Schema::hasColumn('court_case_evidences', 'title')) {
            $insert['title'] = mb_substr($title, 0, 255);
        }
        if (Schema::hasColumn('court_case_evidences', 'description')) {
            $insert['description'] = $source;
        }
        if (Schema::hasColumn('court_case_evidences', 'mime')) {
            $insert['mime'] = $file->getClientMimeType() ?: 'application/pdf';
        }
        if (Schema::hasColumn('court_case_evidences', 'size')) {
            $insert['size'] = $file->getSize();
        }

        DB::table('court_case_evidences')->insert($insert);
    }
}
