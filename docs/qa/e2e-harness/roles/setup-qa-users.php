<?php
// Creates (or refreshes) one QA account per role for the role-by-role E2E walkthrough.
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

// Set QA_PASSWORD for a known password; otherwise a random one is generated and written to qa-users.json.
$password = getenv('QA_PASSWORD') ?: 'Qa!'.Str::random(18).'-9';
$root = '01a0deef-ce0f-739e-841e-140ad65b3b59';      // Addis Ababa City Administration
$psb  = '01a0deef-ce1e-7237-89b1-df18a0cdf27c';      // Public Service Bureau (hr.officer's org)
$out = getenv('QA_OUT') ?: 'qa-users.json';

$matrix = [];
try {
    $ref = new ReflectionClass(DefaultRoleMatrix::class);
    foreach (['roles', 'all', 'definitions', 'matrix'] as $m) {
        if ($ref->hasMethod($m) && $ref->getMethod($m)->isStatic() && $ref->getMethod($m)->getNumberOfRequiredParameters() === 0) {
            $matrix = DefaultRoleMatrix::{$m}();
            break;
        }
    }
} catch (Throwable $e) { echo 'matrix: '.$e->getMessage().PHP_EOL; }
echo 'matrix roles: '.count($matrix).PHP_EOL;

// Drift between the shipped matrix and the database.
$dbRoles = Role::with('permissions')->get()->keyBy('name');
foreach ($matrix as $name => $def) {
    if (! isset($dbRoles[$name])) { echo "DRIFT missing-in-db: $name\n"; continue; }
    $want = collect($def['permissions'] ?? [])->unique();
    $have = $dbRoles[$name]->permissions->pluck('name');
    $miss = $want->diff($have)->values();
    $extra = $have->diff($want)->values();
    if ($miss->isNotEmpty() || $extra->isNotEmpty()) {
        echo "DRIFT perms: $name missing=".$miss->count().' extra='.$extra->count().' e.g. missing['.$miss->take(4)->implode(',').'] extra['.$extra->take(4)->implode(',').']'.PHP_EOL;
    }
}
foreach ($dbRoles as $name => $r) if ($matrix && ! isset($matrix[$name])) echo "DRIFT not-in-matrix: $name\n";

$employeeId = DB::table('employees')->where('employee_number', 'EMP-SRFQZPOK')->value('id');
$accounts = [];
foreach (Role::where('guard_name', 'web')->orderBy('name')->get() as $role) {
    $slug = Str::slug($role->name);
    $email = "qa-role-$slug@example.test";
    $user = User::firstOrNew(['email' => $email]);
    $user->forceFill([
        'name' => 'QA '.$role->name,
        'password' => Hash::make($password),
        'status' => 'active',
        'user_type' => 'staff',
        'email_verified_at' => now(),
        'must_change_password' => false,
        'password_changed_at' => now(),
        'first_login_at' => now(),
        'is_demo' => true,
        'default_organization_id' => (($matrix[$role->name]['scope'] ?? 'scoped') === 'global') ? $root : $psb,
    ])->save();
    $user->syncRoles([$role->name]);
    if ($role->name === 'Employee' && $employeeId) {
        User::where('employee_id', $employeeId)->where('id', '!=', $user->id)->update(['employee_id' => null]);
        $user->forceFill(['employee_id' => $employeeId])->save();
    }
    UserOrganizationScope::where('user_id', $user->id)->delete();
    $global = (($matrix[$role->name]['scope'] ?? 'scoped') === 'global');
    UserOrganizationScope::create([
        'user_id' => $user->id,
        'organization_id' => $global ? $root : $psb,
        'scope_type' => $global ? 'citywide' : 'subtree',
        'effective_from' => now()->subDay()->toDateString(),
        'is_active' => true,
    ]);
    $accounts[] = [
        'role' => $role->name,
        'email' => $email,
        'scope' => $global ? 'citywide' : 'subtree:PSB',
        'permissions' => $role->permissions()->pluck('name')->values()->all(),
        'mfaRequired' => in_array($role->name, config('security.mfa_required_roles', []), true),
    ];
}
file_put_contents($out, json_encode(['password' => $password, 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'accounts: '.count($accounts).' -> '.$out.PHP_EOL;
