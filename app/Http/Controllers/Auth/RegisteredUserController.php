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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
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

        // Only a request for an eligible employee with both delivery channels
        // can reserve quota. Invalid records must not let an accidental or
        // malicious form submission lock the employee out of registration.
        $rateLimitKeys = $this->reserveCodeRequestQuota($request, (string) $validated['employee_number']);

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

        // SMS first: it is the channel that fails most often, and an email
        // code sent before a failed SMS would be one that can never be used.
        try {
            $smsDelivered = $this->smsGateway->send($phone, $notification->toSmsText());
        } catch (Throwable $exception) {
            Log::error('Employee registration OTP SMS failed.', [
                'employee_id' => $employee->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        if ($smsDelivered) {
            try {
                Notification::route('mail', $email)->notify($notification);
                $emailDelivered = true;
            } catch (Throwable $exception) {
                Log::error('Employee registration OTP email failed.', [
                    'employee_id' => $employee->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! $emailDelivered || ! $smsDelivered) {
            $otp->forceFill(['expires_at' => now()->subSecond()])->save();

            // Do not consume quota when neither channel received a code. If
            // one channel succeeded, retain the reservation: refunding it
            // would permit unlimited messages to that contact while the other
            // provider is unavailable.
            if (! $emailDelivered && ! $smsDelivered) {
                $this->refundCodeRequestQuota($rateLimitKeys);
            }

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

            // An account may predate its employee record. Once both employee
            // contacts have been verified, attach that unlinked account instead
            // of rejecting the employee merely because its email is unique.
            $user = User::query()
                ->where('email', $employee->email)
                ->lockForUpdate()
                ->first();

            if ($user !== null && ($user->employee_id !== null || $user->employee_reference !== null)) {
                throw ValidationException::withMessages([
                    'employee_number' => __('auth.employee_already_registered'),
                ]);
            }

            $user ??= new User;

            $user->fill([
                'name' => $employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'email' => $employee->email,
                'phone_number' => $employee->phone,
                'password' => Hash::make((string) $validated['password']),
                'employee_reference' => $employee->employee_number,
                'password_changed_at' => now(),
                'first_login_at' => $user->first_login_at ?? now(),
                'last_login_at' => now(),
            ]);
            // Link to the employee whose contacts were just proved, rather than
            // relying on the email match, which fails when two records share
            // an email and would follow the email if HR later changed it.
            // The code went to that email, so the address is verified too.
            $user->forceFill([
                'employee_id' => $employee->getKey(),
                'employee_link_locked' => true,
                'email_verified_at' => now(),
            ])->save();

            return $user;
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
        $request->session()->regenerate();

        return redirect()->route('employee.portal');
    }

    /**
     * At most 3 codes per employee number and 10 per IP address in 10 minutes.
     * This is kept in the controller so the limit is a field message instead
     * of an error page that loses the form. The reservation is refunded when
     * neither delivery channel succeeds, so a provider outage cannot consume
     * a person's registration attempts.
     *
     * @return array<int, string>
     */
    private function reserveCodeRequestQuota(Request $request, string $employeeNumber): array
    {
        $keys = [
            'registration-send:'.mb_strtolower(trim($employeeNumber)).'|'.$request->ip() => 3,
            'registration-send-ip:'.$request->ip() => 10,
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'employee_number' => __('auth.registration_otp_throttled', [
                        'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                    ]),
                ]);
            }
        }

        $keys = array_keys($keys);

        foreach ($keys as $key) {
            RateLimiter::hit($key, 600);
        }

        return $keys;
    }

    /** @param array<int, string> $keys */
    private function refundCodeRequestQuota(array $keys): void
    {
        foreach ($keys as $key) {
            RateLimiter::decrement($key, 600);
        }
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
        if (self::blockingAccounts($employee)->exists()) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_already_registered'),
            ]);
        }
    }

    /**
     * Accounts that already belong to this employee: linked to the record,
     * carrying its employee number, or an employee account using its email.
     *
     * A blank email or number is never compared: where('email', null) is
     * "email IS NULL", which would match every account without an email and
     * tell an employee with no account that one already exists.
     * Also used by `php artisan registration:diagnose`.
     *
     * @return Builder<User>
     */
    public static function blockingAccounts(Employee $employee): Builder
    {
        $number = trim((string) $employee->employee_number);
        $email = trim((string) $employee->email);

        return User::query()->where(function ($query) use ($employee, $number, $email): void {
            $query->where('employee_id', $employee->id);

            if ($number !== '') {
                $query->orWhere('employee_reference', $number);
            }

            if ($email !== '') {
                $query->orWhere(function ($query) use ($email): void {
                    $query->whereRaw('lower(email) = ?', [mb_strtolower($email)])
                        ->where(function ($query): void {
                            $query->whereNotNull('employee_reference')
                                ->orWhereNotNull('employee_id');
                        });
                });
            }
        });
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
