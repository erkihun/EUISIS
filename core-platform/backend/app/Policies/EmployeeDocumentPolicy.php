<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/**
 * Who may see, add, verify and withdraw an employee's evidence (ADR 0009).
 * Each kind has its own permissions; a signature is the most restricted.
 * The scope is the employee's organization, so knowing a document's id is
 * never enough.
 */
final class EmployeeDocumentPolicy
{
    use ChecksOrganizationScope;

    private const PERMISSIONS = [
        EmployeeDocument::DOCUMENT => ['list' => 'employee_documents.view', 'read' => 'employee_documents.download', 'upload' => 'employee_documents.upload', 'verify' => 'employee_documents.verify'],
        EmployeeDocument::PHOTO => ['list' => 'employee_photos.view', 'read' => 'employee_photos.view', 'upload' => 'employee_photos.upload', 'verify' => 'employee_photos.verify'],
        EmployeeDocument::SIGNATURE => ['list' => 'employee_signatures.view', 'read' => 'employee_signatures.view', 'upload' => 'employee_signatures.upload', 'verify' => 'employee_signatures.verify'],
    ];

    /** Seeing that this kind of evidence exists for the employee, and its metadata. */
    public function viewAny(User $user, Employee $employee, string $kind): bool
    {
        return $this->permits($user, self::PERMISSIONS[$kind]['list'] ?? 'none', $this->employeeOrganization($employee));
    }

    /** Reading the file itself. */
    public function download(User $user, EmployeeDocument $document): bool
    {
        return $this->permits($user, self::PERMISSIONS[$document->kind]['read'] ?? 'none', $this->employeeOrganization($document->employee_id));
    }

    public function upload(User $user, Employee $employee, string $kind): bool
    {
        return $this->permits($user, self::PERMISSIONS[$kind]['upload'] ?? 'none', $this->employeeOrganization($employee), ! $employee->isSeparated());
    }

    public function replace(User $user, EmployeeDocument $document): bool
    {
        return $this->permits($user, self::PERMISSIONS[$document->kind]['upload'] ?? 'none', $this->employeeOrganization($document->employee_id),
            $document->is_current && $document->status !== EmployeeDocument::ARCHIVED);
    }

    public function verify(User $user, EmployeeDocument $document): bool
    {
        return $this->permits($user, self::PERMISSIONS[$document->kind]['verify'] ?? 'none', $this->employeeOrganization($document->employee_id),
            $document->is_current && $document->status === EmployeeDocument::PENDING && (int) $document->uploaded_by !== (int) $user->getKey());
    }

    public function archive(User $user, EmployeeDocument $document): bool
    {
        return $this->permits($user, 'employee_documents.archive', $this->employeeOrganization($document->employee_id), $document->is_current);
    }
}
