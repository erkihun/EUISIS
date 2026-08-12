<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Decides whether a user must enrol/use multi-factor authentication.
 *
 * The System Settings > Security "Enable MFA" switch (default on) is the
 * master gate. While it is on, two sources are combined (union):
 *  1. The env-configured baseline role names from
 *     config('security.mfa_required_roles'), preserved from the original
 *     implementation so untouched deployments keep their guarantees.
 *  2. The settings rules: "require for all users", a role-id checklist, and a
 *     backward-compatible mapping of the legacy require_mfa_for_admins flag
 *     onto config('security.mfa_privileged_roles').
 */
class MfaRequirementService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    public function requiresMfa(User $user): bool
    {
        $security = $this->settings->getGroup(SystemSettingsRegistry::GROUP_SECURITY);

        // "Enable MFA" is the admin-facing master switch: when off, nobody is
        // forced through MFA (defaults to on, so the env baseline below keeps
        // applying on systems that never touched the setting).
        if (! (bool) ($security['mfa_enabled'] ?? true)) {
            return false;
        }

        // Env baseline: database settings can only add to it while MFA is on.
        $baselineRoles = (array) config('security.mfa_required_roles', []);
        if ($baselineRoles !== [] && $user->hasAnyRole($baselineRoles)) {
            return true;
        }

        if ((bool) ($security['mfa_required_for_all'] ?? false)) {
            return true;
        }

        // Role-id matching is guard-aware by construction: role ids are
        // unique across guards, so no display-name comparison is needed.
        $requiredRoleIds = array_map(strval(...), (array) ($security['mfa_required_role_ids'] ?? []));
        if ($requiredRoleIds !== []) {
            $userRoleIds = $user->roles->pluck('id')->map(fn ($id): string => (string) $id)->all();
            if (array_intersect($requiredRoleIds, $userRoleIds) !== []) {
                return true;
            }
        }

        // Legacy fallback: old "Require MFA For Admins" maps to the privileged
        // role names until administrators save the new role-based settings.
        if ((bool) ($security['require_mfa_for_admins'] ?? false)) {
            $privileged = (array) config('security.mfa_privileged_roles', []);
            if ($privileged !== [] && $user->hasAnyRole($privileged)) {
                return true;
            }
        }

        return false;
    }
}
