<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

/**
 * The coarse route groups telemetry is counted by, and the business flows
 * that depend on them. A route group is a name, never a URL with parameters,
 * so counters stay low-cardinality and carry no identifiers.
 */
final class RouteGroups
{
    public const PUBLIC_FEEDBACK = 'public_feedback';

    public const DAILY_ACTIVITY = 'daily_activity';

    public const CAFETERIA = 'cafeteria';

    public const PERFORMANCE = 'performance';

    public const FIELD_WORK = 'field_work';

    public const TRANSFERS = 'transfers';

    public const EMPLOYEE_PORTAL = 'employee_portal';

    public const AUTH = 'auth';

    public const API = 'api';

    public const ADMIN = 'admin';

    public const OTHER = 'other';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PUBLIC_FEEDBACK, self::DAILY_ACTIVITY, self::CAFETERIA, self::PERFORMANCE, self::FIELD_WORK,
            self::TRANSFERS, self::EMPLOYEE_PORTAL, self::AUTH, self::API, self::ADMIN, self::OTHER,
        ];
    }

    /** The group of a route name (or URI path when the route is unnamed). */
    public static function of(?string $routeName, string $path): string
    {
        $name = (string) $routeName;
        $path = '/'.ltrim($path, '/');

        return match (true) {
            str_starts_with($name, 'service-feedback.') || str_starts_with($name, 'public.feedback') || str_starts_with($path, '/service-feedback') || str_starts_with($path, '/feedback') => self::PUBLIC_FEEDBACK,
            str_contains($name, 'daily-activit') || str_starts_with($path, '/my-portal/daily-activity') || str_starts_with($path, '/daily-activities') => self::DAILY_ACTIVITY,
            str_starts_with($name, 'cafeteria') || str_starts_with($path, '/cafeteria') => self::CAFETERIA,
            str_starts_with($name, 'performance.') || str_starts_with($name, 'employee.performance') || str_starts_with($path, '/performance') => self::PERFORMANCE,
            str_contains($name, 'field-work') || str_starts_with($path, '/field-work') || str_starts_with($path, '/my-portal/field-work') => self::FIELD_WORK,
            str_starts_with($name, 'transfers.') || str_starts_with($name, 'employee.transfer') || str_starts_with($path, '/transfers') => self::TRANSFERS,
            str_starts_with($name, 'employee.') || str_starts_with($path, '/my-portal') => self::EMPLOYEE_PORTAL,
            str_starts_with($name, 'login') || str_starts_with($name, 'password.') || str_starts_with($name, 'mfa.') || str_starts_with($name, 'logout') || str_starts_with($path, '/login') => self::AUTH,
            str_starts_with($path, '/api/') => self::API,
            $name !== '' => self::ADMIN,
            default => self::OTHER,
        };
    }
}
