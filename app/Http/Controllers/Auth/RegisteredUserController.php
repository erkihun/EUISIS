<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\DefaultPasswordPolicyService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly DefaultPasswordPolicyService $defaultPasswordPolicy) {}

    /**
     * Display the registration view, or redirect to /login when public
     * self-service registration is disabled.
     */
    public function create(): Response|RedirectResponse
    {
        if ($redirect = $this->disabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     * Employees identify themselves by their employee number; the system
     * pulls their name and email from the existing Employee record.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->disabledRedirect()) {
            return $redirect;
        }

        $request->validate([
            'employee_number' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', $this->defaultPasswordPolicy->rule()],
        ]);

        if ($this->defaultPasswordPolicy->matches((string) $request->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password_cannot_be_default'),
            ]);
        }

        // Find the employee by employee_number
        $employee = Employee::query()
            ->where('employee_number', $request->employee_number)
            ->first();

        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_not_found'),
            ]);
        }

        if (empty($employee->email)) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_no_email'),
            ]);
        }

        // Block duplicate accounts
        if (User::where('email', $employee->email)->exists()) {
            throw ValidationException::withMessages([
                'employee_number' => __('auth.employee_already_registered'),
            ]);
        }

        $user = User::create([
            'name' => $employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
            'email' => $employee->email,
            'password' => Hash::make($request->password),
            'employee_reference' => $employee->employee_number,
            'password_changed_at' => now(),
            'first_login_at' => now(),
            'last_login_at' => now(),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('employee.portal');
    }

    /**
     * Returns a redirect to /login when public self-service registration is
     * disabled, or null when registration is on.
     */
    private function disabledRedirect(): ?RedirectResponse
    {
        if (config('security.registration_enabled', false)) {
            return null;
        }

        return redirect()->route('login')
            ->with('status', __('security.registration_disabled'));
    }
}
