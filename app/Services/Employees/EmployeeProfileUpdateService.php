<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeePhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeProfileUpdateService
{
    public function employee(User $user): Employee
    {
        $user->unsetRelation('employee');
        $employee = $user->employee;
        abort_unless($employee && $user->isActive(), 403);
        if (! $user->employee_id) {
            abort_unless(Employee::query()->where('email', $user->email)->count() === 1, 403);
            $user->forceFill(['employee_id' => $employee->id, 'employee_link_locked' => true])->save();
        }

        return $employee;
    }

    public function update(User $user, array $data, ?UploadedFile $photo = null): Employee
    {
        if (array_diff(array_keys($data), EmployeeSelfServicePolicy::EDITABLE)) {
            throw ValidationException::withMessages(['profile' => __('employee-portal.forbidden_field')]);
        }
        $employee = $this->employee($user);
        $path = null;
        try {
            if ($photo) {
                validator(['photo' => $photo], ['photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000']])->validate();
                $path = EmployeePhotoStorage::store($photo);
                if (! $path) {
                    throw ValidationException::withMessages(['photo' => __('employee-portal.upload_failed')]);
                }
                $data['photo_path'] = $path;
            }

            return DB::transaction(function () use ($employee, $user, $data) {
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                foreach ($data as $field => $value) {
                    $employee->setAttribute($field, is_string($value) ? trim($value) : $value);
                }
                $changed = array_keys($employee->getDirty());
                $employee->save();
                if ($changed !== []) {
                    app(WriteAuditLogAction::class)->execute(AuditEventType::EmployeeProfileUpdated, $user, $employee, $employee->currentAssignment?->organization_id, newValues: ['changed_fields' => $changed]);
                }

                return $employee;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
