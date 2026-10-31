<?php

declare(strict_types=1);

namespace Modules\Court\Http\Support\Ui;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Support\EuisisParties;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The applicant and respondent portals in the EUISIS shell: which portal a
 * request belongs to, who is signed in to it, its sidebar (the links of the
 * Blade portal layout, components/applicant-layout) and the routes the header
 * uses for home, profile and sign-out. A person files cases as an applicant
 * and answers them as a respondent with the same account (the applicant guard).
 */
final class PortalNavigation
{
    /** @var array<string, list<array{label_key: string, groups: list<array{key: string, label_key: string, icon: string, items: list<array{route: string, label_key: string, icon: string}>}>}>> */
    private const SECTIONS = [
        'applicant' => [[
            'label_key' => 'cm.app.court_portal',
            'groups' => [[
                'key' => 'applicantCases', 'label_key' => 'cm.app.my_cases', 'icon' => 'ClipboardListIcon',
                'items' => [
                    ['route' => 'court.applicant.cases.index', 'label_key' => 'cm.app.my_cases', 'icon' => 'ClipboardListIcon'],
                    ['route' => 'court.applicant.cases.create', 'label_key' => 'cm.app.new_case', 'icon' => 'FilePenIcon'],
                    ['route' => 'court.applicant.lawyer.file', 'label_key' => 'cm.lawyer.file.nav', 'icon' => 'Briefcase', 'lawyer' => true],
                    ['route' => 'court.applicant.response-replies.index', 'label_key' => 'cm.respondent.applicant_response_replies', 'icon' => 'ClipboardTextIcon'],
                    ['route' => 'court.applicant.notifications.index', 'label_key' => 'cm.app.notifications', 'icon' => 'ClipboardClockIcon'],
                    ['route' => 'court.applicant.profile.edit', 'label_key' => 'cm.app.profile', 'icon' => 'UserCircleIcon'],
                ],
            ]],
        ]],
        'respondent' => [[
            'label_key' => 'cm.respondent.respondent_view',
            'groups' => [[
                'key' => 'respondentCases', 'label_key' => 'cm.respondent.my_cases', 'icon' => 'ClipboardListIcon',
                'items' => [
                    ['route' => 'court.respondent.case.search', 'label_key' => 'cm.respondent.find_case', 'icon' => 'FileSearchIcon'],
                    ['route' => 'court.respondent.cases.my', 'label_key' => 'cm.respondent.my_cases', 'icon' => 'ClipboardListIcon'],
                    ['route' => 'court.respondent.responses.index', 'label_key' => 'cm.respondent.my_responses', 'icon' => 'FilePenIcon'],
                    ['route' => 'court.respondent.profile.edit', 'label_key' => 'cm.respondent.profile', 'icon' => 'UserCircleIcon'],
                ],
            ]],
        ]],
    ];

    /**
     * The header's routes. `feed`, `read`, `readAll` and `viewAll` are the notification bell's
     * (PortalNotificationFeedController); the respondent portal lists its notifications on its dashboard.
     *
     * @var array<string, array{home: string, profile: string, logout: string, switch: ?string, feed: string, read: string, readAll: string, viewAll: string}>
     */
    private const ROUTES = [
        'applicant' => [
            'home' => 'court.applicant.dashboard', 'profile' => 'court.applicant.profile.edit', 'logout' => 'court.applicant.logout', 'switch' => 'court.respondent.dashboard',
            'feed' => 'court.applicant.notifications.feed', 'read' => 'court.applicant.notifications.read', 'readAll' => 'court.applicant.notifications.read-all', 'viewAll' => 'court.applicant.notifications.index',
        ],
        'respondent' => [
            'home' => 'court.respondent.dashboard', 'profile' => 'court.respondent.profile.edit', 'logout' => 'court.respondent.logout', 'switch' => 'court.respondent.switchToApplicant',
            'feed' => 'court.respondent.notifications.feed', 'read' => 'court.respondent.notifications.read', 'readAll' => 'court.respondent.notifications.read-all', 'viewAll' => 'court.respondent.dashboard',
        ],
    ];

    /** 'applicant', 'respondent' or null (the staff application). Read at render time, after the route's guard middleware. */
    public static function portalFor(Request $request): ?string
    {
        if ($request->is('court/respondent', 'court/respondent/*', 'court/applicant/respondent/*')) {
            return 'respondent';
        }
        if ($request->is('court/applicant', 'court/applicant/*')) {
            return 'applicant';
        }
        $driver = Auth::getDefaultDriver();

        return in_array($driver, ['applicant', 'respondent'], true) ? $driver : null;
    }

    public static function user(): ?Applicant
    {
        $user = Auth::guard('applicant')->user();

        return $user instanceof Applicant ? $user : null;
    }

    /** @return list<array<string, mixed>> */
    public static function sections(string $portal): array
    {
        $user = self::user();
        $lawyer = $user !== null && CaseParties::isVerifiedLawyer($user);

        return collect(self::SECTIONS[$portal] ?? [])
            ->map(fn (array $section): array => [
                ...$section,
                'groups' => collect($section['groups'])
                    // With EUISIS parties only, new cases are filed from the EUISIS employee portal;
                    // a verified lawyer files for a client (EUISIS ADR 0013 #13).
                    ->map(fn (array $group): array => [...$group, 'items' => array_values(array_map(
                        fn (array $item): array => array_diff_key($item, ['lawyer' => true]),
                        array_filter($group['items'], fn (array $item): bool => Route::has($item['route'])
                            && ! ($item['route'] === 'court.applicant.cases.create' && EuisisParties::only())
                            && (! ($item['lawyer'] ?? false) || $lawyer)),
                    ))])
                    ->filter(fn (array $group): bool => $group['items'] !== [])
                    ->values()->all(),
            ])
            ->values()->all();
    }

    /** @return array{name: string, home: string, profile: string, logout: string, switch: ?string, feed: string, read: string, readAll: string, viewAll: string, filesInEuisis: bool} */
    public static function routes(string $portal): array
    {
        return ['name' => $portal, ...self::ROUTES[$portal], 'filesInEuisis' => EuisisParties::only()];
    }
}
