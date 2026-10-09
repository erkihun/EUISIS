<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\TransferDocumentVerificationStatus;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

readonly class SubmitTransferApplicationAction
{
    public function __construct(private CreateTransferApplicationAction $createAction) {}

    /**
     * @param  array{announcement_position_id?: string|null, cover_letter?: string|null, documents?: array<int, UploadedFile>|null, reuse_document_ids?: array<int, string>|null}  $data
     */
    public function execute(
        TransferAnnouncement $announcement,
        Employee $employee,
        User $actor,
        array $data = [],
    ): TransferApplication {
        $documents = $this->resolveDocuments($announcement, $employee, $data);
        $application = $this->createAction->execute(
            $announcement,
            $employee,
            $actor,
            [
                'announcement_position_id' => $data['announcement_position_id'] ?? null,
                'applicant_notes' => $data['cover_letter'] ?? null,
            ],
        );

        foreach ($documents as $document) {
            if ($document['upload'] instanceof UploadedFile) {
                $file = $document['upload'];
                $path = $file->store('transfer-documents/'.$application->id, 'local');
                $originalName = $file->getClientOriginalName();
                $mimeType = $file->getMimeType();
                $fileSize = $file->getSize();
            } else {
                /** @var EmployeeDocument $source */
                $source = $document['reuse'];
                $diskName = array_key_exists((string) $source->storage_disk, (array) config('filesystems.disks'))
                    ? $source->storage_disk
                    : 'local';
                $disk = Storage::disk($diskName ?: 'local');
                if (str_contains($source->file_path, '..') || ! $disk->exists($source->file_path)) {
                    throw new DomainException('A selected employee document is no longer available.');
                }
                $path = 'transfer-documents/'.$application->id.'/'.Str::uuid7();
                Storage::disk('local')->put($path, $disk->get($source->file_path));
                $originalName = basename($source->file_path);
                $mimeType = (string) ($disk->mimeType($source->file_path) ?: 'application/octet-stream');
                $fileSize = (int) ($disk->size($source->file_path) ?: 0);
            }

            $application->documents()->create([
                'document_type' => $document['type'],
                'original_name' => $originalName,
                'file_name' => basename((string) $path),
                'file_path' => $path,
                'file_type' => $mimeType,
                'file_size' => $fileSize,
                'verification_status' => TransferDocumentVerificationStatus::Pending,
            ]);
        }

        return $application;
    }

    /**
     * Required documents are selected by server-defined ordinal, never from a
     * browser supplied document type.  A reused employee document is copied
     * into the application record so later profile edits cannot rewrite what
     * the reviewer assessed.
     *
     * @return list<array{type: string, upload: UploadedFile|null, reuse: EmployeeDocument|null}>
     */
    private function resolveDocuments(TransferAnnouncement $announcement, Employee $employee, array $data): array
    {
        $required = collect([
            ...(array) TransferSetting::current()->required_documents,
            ...(array) $announcement->required_documents,
        ])->filter(fn (mixed $type): bool => is_string($type) && trim($type) !== '')
            ->map(fn (string $type): string => trim($type))->unique()->values();
        $uploads = (array) ($data['documents'] ?? []);
        $reuseIds = (array) ($data['reuse_document_ids'] ?? []);
        $documents = [];

        foreach ($required as $index => $type) {
            $upload = ($uploads[$index] ?? null) instanceof UploadedFile ? $uploads[$index] : null;
            $reuse = null;
            if ($upload === null && filled($reuseIds[$index] ?? null)) {
                $reuse = EmployeeDocument::query()
                    ->where('employee_id', $employee->id)
                    ->where('document_type', $type)
                    ->find($reuseIds[$index]);
            }
            if ($upload === null && $reuse === null) {
                throw new DomainException("Required document missing: {$type}.");
            }
            $documents[] = ['type' => $type, 'upload' => $upload, 'reuse' => $reuse];
        }

        if ($required->isEmpty()) {
            foreach ($uploads as $upload) {
                if (! $upload instanceof UploadedFile) {
                    continue;
                }
                $documents[] = ['type' => 'applicant_upload', 'upload' => $upload, 'reuse' => null];
            }
        }

        return $documents;
    }
}
