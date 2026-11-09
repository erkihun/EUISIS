<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * Records the Super Admin role never opens by itself: their policies decide
 * (membership, an approved grant). A module registers its classes from its
 * service provider; the Gate's role bypass (AppServiceProvider) consults the list.
 */
final class RoleBypassExemptions
{
    /** @var list<class-string> */
    private static array $classes = [];

    /** @param list<class-string> $classes */
    public static function register(array $classes): void
    {
        self::$classes = array_values(array_unique([...self::$classes, ...$classes]));
    }

    /** @return list<class-string> */
    public static function classes(): array
    {
        return self::$classes;
    }
}
