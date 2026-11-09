<?php
// Disables the role-walkthrough QA accounts. They cannot be deleted (audit_logs reference them).
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$n = 0;
foreach (User::where('email', 'like', 'qa-role-%@example.test')->get() as $u) {
    $u->syncRoles([]);
    UserOrganizationScope::where('user_id', $u->id)->delete();
    $u->forceFill([
        'status' => 'inactive', 'password' => Hash::make(Str::random(40)), 'employee_id' => null,
        'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_enabled' => false,
    ])->save();
    DB::table('sessions')->where('user_id', (string) $u->id)->delete();
    $n++;
}
echo "disabled $n QA accounts\n";
