<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Contracts\SmsGateway;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\EmployeeContactVerification;
use App\Models\User;
use App\Notifications\EmployeeContactCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class EmployeeContactVerificationService
{
    public function request(User $user, string $field, string $value): void
    {
        $employee = app(EmployeeProfileUpdateService::class)->employee($user);
        $value = $field === 'email' ? mb_strtolower(trim($value)) : preg_replace('/[\s()-]/', '', $value);
        $this->validateValue($employee, $field, $value);
        $code = (string) random_int(100000, 999999);
        $verification = DB::transaction(function () use ($employee, $user, $field, $value, $code) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            EmployeeContactVerification::query()->where('employee_id', $employee->id)->where('field', $field)->whereNull('consumed_at')->update(['expires_at' => now()]);

            return EmployeeContactVerification::create(['employee_id' => $employee->id, 'user_id' => $user->id, 'field' => $field, 'value' => $value, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
        });
        try {
            if ($field === 'email') {
                Notification::route('mail', $value)->notify(new EmployeeContactCode($code));
            } else {
                $sms = app(SmsGateway::class);
                if (! $sms->isConfigured() || ! $sms->send($value, __('employee-portal.code_message', ['code' => $code]))) {
                    throw new \RuntimeException('Contact delivery unavailable');
                }
            }
        } catch (\Throwable) {
            $verification->update(['expires_at' => now()]);
            throw ValidationException::withMessages(['value' => __('employee-portal.delivery_failed')]);
        }
    }

    public function confirm(User $user, string $field, string $code): void
    {
        $employee = app(EmployeeProfileUpdateService::class)->employee($user);
        $valid = DB::transaction(function () use ($user, $employee, $field, $code) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $verification = EmployeeContactVerification::query()->where('employee_id', $employee->id)->where('user_id', $user->id)->where('field', $field)->whereNull('consumed_at')->where('expires_at', '>', now())->latest('created_at')->lockForUpdate()->first();
            if (! $verification || $verification->attempts >= 5) {
                return false;
            }
            $verification->increment('attempts');
            if (! Hash::check($code, $verification->code_hash)) {
                return false;
            }
            $this->validateValue($employee, $field, $verification->value);
            $employee->setAttribute($field, $verification->value);
            $employee->save();
            // Personal contacts are distinct from login email and account recovery contacts.
            // The stable employee_id retains ownership if the personal email changes.
            $verification->update(['consumed_at' => now()]);
            app(WriteAuditLogAction::class)->execute(AuditEventType::EmployeeProfileUpdated, $user, $employee, $employee->currentAssignment?->organization_id, newValues: ['changed_fields' => [$field], 'verified' => true]);

            return true;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['otp' => __('employee-portal.invalid_code')]);
        }
    }

    private function validateValue(Employee $employee, string $field, string $value): void
    {
        abort_unless(in_array($field, ['email', 'phone'], true), 422);
        validator(['value' => $value], ['value' => $field === 'email' ? ['required', 'email:rfc', 'max:255'] : ['required', 'regex:/^\+?[0-9]{9,15}$/']])->validate();
        if (Employee::query()->where($field, $value)->whereKeyNot($employee->id)->exists()
            || ($field === 'email' && User::query()->where('email', $value)->where('id', '!=', auth()->id())->exists())) {
            throw ValidationException::withMessages(['value' => __('employee-portal.contact_unavailable')]);
        }
    }
}
