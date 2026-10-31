<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\EuisisEmployee;
use Modules\Court\Domain\Models\EuisisOrganizationUnit;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\Euisis\EuisisClient;
use Modules\Court\Application\Services\Euisis\EuisisException;
use Modules\Court\Application\Services\Euisis\StaffLink;
use Modules\Court\Application\Services\Euisis\StaffLinkException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UsersController extends Controller
{
    /** List users (requires users.manage via routes) */
    public function index(Request $request)
    {
        $q = trim($request->get('q', ''));
        $status = $request->get('status');

        $users = User::query()
            ->with('roles')
            ->when(
                $q !== '',
                fn ($b) => $b->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                })
            )
            ->when(in_array($status, ['active', 'inactive']), fn ($b) => $b->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        // The register (React, Court/Users/Index); each row carries what this viewer may do (UserPolicy).
        $viewer = $request->user();

        return Inertia::render('Court/Users/Index', [
            'users' => $users->through(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'avatar_url' => $user->avatar_url,
                'has_signature' => filled($user->signature_path),
                'roles' => $user->roles->pluck('name')->values()->all(),
                'status' => (string) ($user->status ?? 'inactive'),
                'verified' => $user->hasVerifiedEmail(),
                'created_at' => $user->created_at?->toIso8601String(),
                'can' => [
                    'view' => (bool) $viewer?->can('view', $user),
                    'update' => (bool) $viewer?->can('update', $user),
                    'delete' => (bool) $viewer?->can('delete', $user),
                ],
            ]),
            'filters' => ['q' => $q, 'status' => in_array($status, ['active', 'inactive'], true) ? $status : ''],
            'canCreate' => (bool) ($viewer?->hasPermission('users.manage') ?? false),
        ]);
    }

    /** Show a single profile (policy: view) */
    public function show(Request $request, User $user)
    {
        $this->authorize('view', $user);
        $user->load('roles');

        // The profile page (React, Court/Users/Show).
        return Inertia::render('Court/Users/Show', [
            'user' => [
                ...$this->userIdentity($user),
                'avatar_url' => $user->avatar_url,
                'signature_url' => $user->signature_url,
                'stamp_url' => $user->stamp_url,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'can' => [
                'update' => (bool) $request->user()?->can('update', $user),
                'linkEuisis' => $this->canManage($request->user()) && (bool) $request->user()?->can('update', $user),
            ],
            'euisis' => $this->euisisPanel($user),
        ]);
    }

    /**
     * The account's EUISIS employee, read-only (docs/euisis-integration.md, "Staff
     * accounts"): who EUISIS says they are and where they work, and whether the
     * EUISIS staff check suspended the account.
     */
    private function euisisPanel(User $user): array
    {
        $staff = app(StaffLink::class);
        $employee = $user->euisis_employee_uuid !== null
            ? EuisisEmployee::query()->with(['organization', 'position'])->find($user->euisis_employee_uuid)
            : null;
        $unit = $employee?->unit_uuid !== null ? EuisisOrganizationUnit::query()->find($employee->unit_uuid) : null;

        return [
            'enabled' => app(EuisisClient::class)->enabled(),
            'linked' => $user->euisis_employee_uuid !== null,
            'employee' => $employee === null ? null : [
                'employee_number' => $employee->employee_number,
                'name' => $employee->displayName(),
                'name_en' => $employee->full_name_en,
                'status' => $employee->status,
                'position' => $employee->position?->displayTitle(),
                'unit' => $unit !== null ? $staff->unitName($unit) : null,
                'organization' => $employee->organization?->displayName(),
                'in_court' => $staff->standing($employee) === null,
                'synced_at' => $employee->synced_at?->toIso8601String(),
            ],
            'linked_at' => $user->euisis_linked_at?->toIso8601String(),
            'suspended_at' => $user->euisis_suspended_at?->toIso8601String(),
            'suspension_reason' => $user->euisis_suspension_reason !== null ? (string) __('court::euisis_staff.reasons.'.$user->euisis_suspension_reason) : null,
        ];
    }

    /** Create form (requires users.manage via routes) */
    public function create(Request $request)
    {
        // The create form (React, Court/Users/Create); from the EUISIS staff roster,
        // `?euisis_employee=<uuid>` fills in the employee's name and number.
        $prefill = null;
        $prefillError = null;
        $uuid = (string) $request->query('euisis_employee', '');
        if ($uuid !== '' && Str::isUuid($uuid)) {
            try {
                $row = app(StaffLink::class)->eligibleByUuid($uuid);
                $name = (string) ($row['full_name'] ?? $row['full_name_en'] ?? '');
                $prefill = ['employee_number' => (string) ($row['employee_number'] ?? ''), 'name' => $name, ...$this->nameParts($name)];
            } catch (StaffLinkException $e) {
                $prefillError = (string) __('court::euisis_staff.errors.'.$e->reason);
            } catch (EuisisException) {
                $prefillError = (string) __('court::euisis_staff.errors.euisis_unavailable');
            }
        }

        return Inertia::render('Court/Users/Create', [
            'roles' => $this->roleOptions($request->user()),
            'euisisPrefill' => $prefill,
            'euisisPrefillError' => $prefillError,
        ]);
    }

    /** Store (requires users.manage via routes) */
    public function store(Request $request)
    {
        // Normalize national ID (strip spaces/non-digits before validation)
        $normalizedNationalId = preg_replace('/\D+/', '', (string) $request->input('national_id'));
        $request->merge(['national_id' => $normalizedNationalId ?: null]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:court_staff,email'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date'],
            'national_id' => ['nullable', 'digits:16'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:court_roles,id'],

            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'signature' => ['nullable', 'image', 'mimes:png,webp,jpg,jpeg', 'max:2048'],
            'stamp' => ['nullable', 'image', 'mimes:png,webp,jpg,jpeg', 'max:2048'],
            // From the EUISIS staff roster: the account is linked to this employee.
            'euisis_employee_number' => ['nullable', 'string', 'max:50'],
        ]);

        $this->ensureAssignableRoles($request->user(), $data['roles'] ?? []);

        $euisisEmployee = null;
        if (filled($data['euisis_employee_number'] ?? null)) {
            try {
                $euisisEmployee = app(StaffLink::class)->eligible((string) $data['euisis_employee_number']);
            } catch (StaffLinkException $e) {
                throw ValidationException::withMessages(['euisis_employee_number' => __('court::euisis_staff.errors.'.$e->reason)]);
            } catch (EuisisException) {
                throw ValidationException::withMessages(['euisis_employee_number' => __('court::euisis_staff.errors.euisis_unavailable')]);
            }
        }

        // Use a random password and force reset via email.
        $rawPassword = Str::random(32);

        $avatarPath = $request->hasFile('avatar')
            ? $request->file('avatar')->store('avatars', 'court_public')
            : null;

        $signaturePath = $request->hasFile('signature')
            ? $request->file('signature')->store('signatures', 'court_public')
            : null;

        $stampPath = $request->hasFile('stamp')
            ? $request->file('stamp')->store('stamps', 'court_public')
            : null;

        $fullName = trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['middle_name'] ?? null,
            $data['last_name'] ?? null,
        ])));

        $account = app(\Modules\Court\Application\Services\CourtStaffAccounts::class)->accountFor($data['email'], $fullName, $rawPassword, $request->user());
        $user = (new User)->forceFill(['id' => $account->getKey()]);
        $user->fill([
            'name' => $fullName,
            'email' => $data['email'],
            'must_change_password' => true,
            'status' => $data['status'],
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'avatar_path' => $avatarPath,
            'signature_path' => $signaturePath,
            'stamp_path' => $stampPath,
        ])->save();

        if (! empty($data['roles'])) {
            $user->roles()->sync($data['roles']);
        }

        if ($euisisEmployee !== null) {
            try {
                app(StaffLink::class)->attach($user, $euisisEmployee);
            } catch (EuisisException $e) {
                // The account stands; the link can be made again from its page.
                Log::warning('EUISIS staff link failed after the account was created', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            Password::broker()->sendResetLink(['email' => $user->email]);
            $message = 'court::messages.success.user_created_reset_sent';
        } catch (\Throwable $e) {
            Log::warning('Failed sending user reset link', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
            $message = 'court::messages.success.user_created_reset_manual';
        }

        return redirect()->route('court.users.index')->with('success', $message);
    }

    /** Edit form (policy: update) */
    public function edit(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $user->load('roles');

        // The edit form (React, Court/Users/Edit): the form's fields with their current values.
        return Inertia::render('Court/Users/Edit', [
            'user' => [
                ...$this->userIdentity($user),
                'gender' => $user->gender,
                'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
                'national_id' => $user->national_id,
                'position' => $user->position,
                'phone' => $user->phone,
                'address' => $user->address,
                'avatar_url' => $user->avatar_url,
                'signature_url' => $user->signature_url,
                'stamp_url' => $user->stamp_url,
                'role_ids' => $user->roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ],
            'roles' => $this->roleOptions($request->user()),
            // Someone editing only their own record (no users.manage) sees the
            // contact fields; roles, status, password, signature and stamp stay hidden.
            'canManage' => $this->canManage($request->user()),
        ]);
    }

    /**
     * Every role, for the role checkboxes. The admin role is offered only to
     * administrators: anyone else holding users.manage could hand it out.
     */
    private function roleOptions(?User $actor): array
    {
        $isAdmin = (bool) $actor?->hasRole('admin');

        return Role::orderBy('name')->get()
            ->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'disabled' => ! $isAdmin && $this->isAdminRole($role),
            ])
            ->values()
            ->all();
    }

    /** Roles, status, password, signature and stamp belong to administrators and users.manage holders. */
    private function canManage(?User $actor): bool
    {
        return $actor !== null && ($actor->hasRole('admin') || $actor->hasPermission('users.manage'));
    }

    private function isAdminRole(Role $role): bool
    {
        return mb_strtolower(trim((string) $role->name)) === 'admin';
    }

    /**
     * Only administrators may grant the admin role; a users.manage holder could
     * otherwise promote themselves or anyone else. A role the account already
     * holds may stay (the policy keeps non-admins away from admin accounts).
     *
     * @param  array<int, int|string>  $roleIds
     */
    private function ensureAssignableRoles(?User $actor, array $roleIds, ?User $target = null): void
    {
        if ($actor?->hasRole('admin')) {
            return;
        }

        $adminRoleIds = Role::query()->whereRaw('LOWER(name) = ?', ['admin'])->pluck('id')->map(fn ($id): int => (int) $id);
        $requested = collect($roleIds)->map(fn ($id): int => (int) $id);
        $alreadyHeld = $target?->roles()->pluck('court_roles.id')->map(fn ($id): int => (int) $id) ?? collect();

        if ($requested->intersect($adminRoleIds)->diff($alreadyHeld)->isNotEmpty()) {
            throw ValidationException::withMessages(['roles' => __('court::users.admin_role_admin_only')]);
        }
    }

    /**
     * Who the account is: the stored full name split into first, middle and last
     * the way the forms read it back (one word is a first name, two are first and
     * last, three or more are first, middle and the rest).
     */
    private function userIdentity(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            ...$this->nameParts((string) ($user->name ?? '')),
            'email' => (string) $user->email,
            'status' => (string) ($user->status ?? 'inactive'),
        ];
    }

    /** @return array{first_name: string, middle_name: string, last_name: string} */
    private function nameParts(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 3) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        return [
            'first_name' => $parts[0] ?? '',
            'middle_name' => count($parts) >= 3 ? $parts[1] : '',
            'last_name' => count($parts) >= 3 ? $parts[2] : ($parts[1] ?? ''),
        ];
    }

    /** Update (policy: update) */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        // UserPolicy lets staff edit their own record. Without users.manage that
        // covers their contact details only: roles, status, password and the
        // signature/stamp printed on official documents stay with administrators,
        // so those fields are neither validated nor read for such a request.
        $canManage = $this->canManage($request->user());

        // Normalize national ID (strip spaces/non-digits before validation)
        $normalizedNationalId = preg_replace('/\D+/', '', (string) $request->input('national_id'));
        $request->merge(['national_id' => $normalizedNationalId ?: null]);

        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('court_staff', 'email')->ignore($user->id)],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date'],
            'national_id' => ['nullable', 'digits:16'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
        if ($canManage) {
            $rules += [
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'password' => ['nullable', 'confirmed', PasswordRule::defaults()],
                'roles' => ['nullable', 'array'],
                'roles.*' => ['integer', 'exists:court_roles,id'],
                'signature' => ['nullable', 'image', 'mimes:png,webp,jpg,jpeg', 'max:2048'],
                'stamp' => ['nullable', 'image', 'mimes:png,webp,jpg,jpeg', 'max:2048'],
                'remove_signature' => ['nullable', 'boolean'],
                'remove_stamp' => ['nullable', 'boolean'],
            ];
        }
        $data = $request->validate($rules);

        if ($canManage) {
            $this->ensureAssignableRoles($request->user(), $data['roles'] ?? [], $user);
        }

        // Update password if provided
        if ($canManage && ! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
            $user->must_change_password = true;
        }

        // Avatar remove / replace
        if ($request->boolean('remove_avatar')) {
            if ($user->avatar_path) {
                Storage::disk('court_public')->delete($user->avatar_path);
            }
            $data['avatar_path'] = null;
        } elseif ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('court_public')->delete($user->avatar_path);
            }
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'court_public');
        }

        // Signature and stamp remove / replace (managers only; otherwise left as they are)
        foreach ($canManage ? ['signature' => 'signatures', 'stamp' => 'stamps'] : [] as $asset => $directory) {
            $column = "{$asset}_path";
            if ($request->boolean("remove_{$asset}")) {
                if ($user->{$column}) {
                    Storage::disk('court_public')->delete($user->{$column});
                }
                $data[$column] = null;
            } elseif ($request->hasFile($asset)) {
                if ($user->{$column}) {
                    Storage::disk('court_public')->delete($user->{$column});
                }
                $data[$column] = $request->file($asset)->store($directory, 'court_public');
            }
        }

        $fullName = trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['middle_name'] ?? null,
            $data['last_name'] ?? null,
        ])));

        // A manager setting the account active overrides an EUISIS staff suspension; while the
        // account stays linked to an employee outside the court, the next check suspends it again.
        if ($canManage && $data['status'] === 'active' && $user->euisis_suspended_at !== null) {
            $user->forceFill(['euisis_suspended_at' => null, 'euisis_suspension_reason' => null]);
        }

        // Fill basic fields
        $user->fill([
            'name' => $fullName,
            'email' => $data['email'],
            'status' => $canManage ? $data['status'] : $user->status,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            // A removed image leaves null in $data; `??` would keep the deleted file's path on the row.
            'avatar_path' => array_key_exists('avatar_path', $data) ? $data['avatar_path'] : $user->avatar_path,
            'signature_path' => array_key_exists('signature_path', $data) ? $data['signature_path'] : $user->signature_path,
            'stamp_path' => array_key_exists('stamp_path', $data) ? $data['stamp_path'] : $user->stamp_path,
        ])->save();

        // The form always carries the full role selection, and an empty one is
        // sent as no field at all (multipart cannot express an empty list), so
        // a manager's submission without roles clears them.
        if ($canManage) {
            $user->roles()->sync($data['roles'] ?? []);
        }

        // The register needs users.manage; someone editing their own record goes back to it.
        return ($canManage ? redirect()->route('court.users.index') : redirect()->route('court.users.show', $user))->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.user')],
        ]);
    }

    /** Destroy (policy: delete) */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        // Clean up files
        if ($user->avatar_path) {
            Storage::disk('court_public')->delete($user->avatar_path);
        }
        if ($user->signature_path) {
            Storage::disk('court_public')->delete($user->signature_path);
        }
        if ($user->stamp_path) {
            Storage::disk('court_public')->delete($user->stamp_path);
        }

        $user->delete();

        return redirect()->route('court.users.index')->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.user')],
        ]);
    }
}
