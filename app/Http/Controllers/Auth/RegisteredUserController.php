<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Contracts\SmsGateway;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeRegistrationOtp;
use App\Models\User;
use App\Notifications\EmployeeRegistrationOtpNotification;
use App\Security\Passwords\PasswordPolicy;
use App\Support\DailyActivity\DailyActivityRoles;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Throwable;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly SmsGateway $smsGateway,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->disabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('Auth/Register', [
            'otpSent' => $request->session()->has('registration_employee_id'),
            'pendingEmployeeNumber' => $request->session()->get('registration_employee_number'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /** Send one code to both contacts already held in the employee record. */
    public function sendOtp(Request $request): RedirectResponse
    {
        if ($redirect = $this->disabledRedirect()) {
            return $redirect;
        }

        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:255'],
        ]);

        $employee = $this->eligibleEmployee((string) $validated['employee_number']);
        $email = trim((string) $employee->email);
        $phone = trim((string) $employee->phone);

        if ($email === '' || $phone === '') {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_no_contact'),
            ]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = DB::transaction(function () use ($employee, $code, $request): EmployeeRegistrationOtp {
            EmployeeRegistrationOtp::query()
                ->where('employee_id', $employee->getKey())
                ->whereNull('verified_at')
                ->update(['expires_at' => now()->subSecond()]);

            return EmployeeRegistrationOtp::query()->create([
                'employee_id' => $employee->getKey(),
                'otp_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(EmployeeRegistrationOtp::TTL_MINUTES),
                'attempts' => 0,
                'ip_address' => $request->ip(),
            ]);
        });

        $notification = new EmployeeRegistrationOtpNotification($code);
        $emailDelivered = false;
        $smsDelivered = false;

        try {
            Notification::route('mail', $email)->notify($notification);
            $emailDelivered = true;
        } catch (Throwable $exception) {
            Log::error('Employee registration OTP email failed.', [
                'employee_id' => $employee->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $smsDelivered = $this->smsGateway->send($phone, $notification->toSmsText());
        } catch (Throwable $exception) {
            Log::error('Employee registration OTP SMS failed.', [
                'employee_id' => $employee->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        if (! $emailDelivered || ! $smsDelivered) {
            $otp->forceFill(['expires_at' => now()->subSecond()])->save();

            throw ValidationException::withMessages([
                'employee_number' => __('auth.registration_otp_delivery_failed'),
            ]);
        }

        $request->session()->put([
            'registration_employee_id' => $employee->getKey(),
            'registration_employee_number' => $employee->employee_number,
        ]);

        return back()->with('status', __('auth.registration_otp_sent'));
    }

    /** Create the account only after the employee proves possession of the stored contacts. */
    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->disabledRedirect()) {
            return $redirect;
        }

        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:255'],
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
            // Account-independent rules only: whose name to check is not
            // known (or proved) until the OTP below is verified.
            'password' => $this->passwordPolicy->rules(),
        ]);

        $pendingEmployeeId = $request->session()->get('registration_employee_id');
        $pendingEmployeeNumber = $request->session()->get('registration_employee_number');

        if (! is_string($pendingEmployeeId) || $pendingEmployeeNumber !== $validated['employee_number']) {
            throw ValidationException::withMessages([
                'otp' => __('auth.registration_otp_required'),
            ]);
        }

        $result = DB::transaction(function () use ($pendingEmployeeId, $validated, $request): User|string {
            $employee = Employee::query()->lockForUpdate()->find($pendingEmployeeId);

            if ($employee === null || $employee->status !== EmployeeStatus::Active) {
                throw ValidationException::withMessages(['employee_number' => __('auth.employee_inactive')]);
            }

            $this->ensureNotRegistered($employee);

            $otp = EmployeeRegistrationOtp::query()
                ->where('employee_id', $employee->getKey())
                ->whereNull('verified_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                throw ValidationException::withMessages(['otp' => __('auth.registration_otp_required')]);
            }

            if ($otp->isExpired()) {
                throw ValidationException::withMessages(['otp' => __('auth.registration_otp_expired')]);
            }

            if (! $otp->hasAttemptsLeft()) {
                throw ValidationException::withMessages(['otp' => __('auth.registration_otp_attempts')]);
            }

            $otp->increment('attempts');

            if (! Hash::check((string) $validated['otp'], $otp->otp_hash)) {
                // Return the validation message so the transaction commits
                // the failed-attempt counter before the response is raised.
                return $otp->fresh()?->hasAttemptsLeft() === false
                    ? __('auth.registration_otp_attempts')
                    : __('auth.registration_otp_invalid');
            }

            /*
             * Only now — possession of the employee's contact proved — may the
             * password be checked against their name, number and contacts.
             * Earlier, the error message would reveal those to anyone who knew
             * an employee number. A failure rolls the whole step back.
             */
            Validator::make(
                ['password' => (string) $validated['password'], 'password_confirmation' => (string) $request->input('password_confirmation')],
                ['password' => $this->passwordPolicy->rules(null, [
                    'first_name' => $employee->first_name, 'middle_name' => $employee->middle_name, 'last_name' => $employee->last_name,
                    'full_name' => $employee->full_name, 'name_en' => $employee->name_en, 'email' => $employee->email,
                    'phone_number' => $employee->phone, 'employee_number' => $employee->employee_number,
                ])],
            )->validate();

            $otp->forceFill(['verified_at' => now()])->save();

            return User::query()->create([
                'name' => $employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'email' => $employee->email,
                'phone_number' => $employee->phone,
                'password' => Hash::make((string) $validated['password']),
                'employee_reference' => $employee->employee_number,
                'password_changed_at' => now(),
                'first_login_at' => now(),
                'last_login_at' => now(),
            ]);
        });

        if (is_string($result)) {
            throw ValidationException::withMessages(['otp' => $result]);
        }

        $user = $result;

        // Self-service role: own daily activity and nothing administrative.
        if (Role::query()->where('name', DailyActivityRoles::EMPLOYEE_ROLE)->where('guard_name', 'web')->exists()) {
            $user->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);
        }

        $request->session()->forget(['registration_employee_id', 'registration_employee_number']);
        event(new Registered($user));
        Auth::login($user);

        return redirect()->route('employee.portal');
    }

    private function eligibleEmployee(string $employeeNumber): Employee
    {
        $employee = Employee::query()
            ->where('employee_number', trim($employeeNumber))
            ->first();

        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_not_found'),
            ]);
        }

        if ($employee->status !== EmployeeStatus::Active) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_inactive'),
            ]);
        }

        $this->ensureNotRegistered($employee);

        return $employee;
    }

    private function ensureNotRegistered(Employee $employee): void
    {
        if (User::query()
            ->where('employee_reference', $employee->employee_number)
            ->orWhere('employee_id', $employee->id)
            ->orWhere('email', $employee->email)
            ->exists()) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_already_registered'),
            ]);
        }
    }

    private function disabledRedirect(): ?RedirectResponse
    {
        if (config('security.registration_enabled', false)) {
            return null;
        }

        return redirect()->route('login')
            ->with('status', __('security.registration_disabled'));
    }
}
