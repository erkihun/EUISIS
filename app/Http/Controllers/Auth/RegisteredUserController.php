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
use App\Services\Security\DefaultPasswordPolicyService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly DefaultPasswordPolicyService $defaultPasswordPolicy,
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
            'password' => ['required', 'confirmed', $this->defaultPasswordPolicy->rule()],
        ]);

        if ($this->defaultPasswordPolicy->matches((string) $validated['password'])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password_cannot_be_default'),
            ]);
        }

        $pendingEmployeeId = $request->session()->get('registration_employee_id');
        $pendingEmployeeNumber = $request->session()->get('registration_employee_number');

        if (! is_string($pendingEmployeeId) || $pendingEmployeeNumber !== $validated['employee_number']) {
            throw ValidationException::withMessages([
                'otp' => __('auth.registration_otp_required'),
            ]);
        }

        $result = DB::transaction(function () use ($pendingEmployeeId, $validated): User|string {
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
