<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\User;
use App\Support\Features;

/**
 * The Situation Room category registry: one place that says which categories
 * exist, in what order, which grant opens each, which icon and accent the
 * launcher card uses, and whether it may appear on a wall display. The
 * launcher, the routes, the controller and the tests all read this list, so
 * adding a category is one case here plus its analytics class.
 *
 * Transport and Service Monitoring follow their module flags
 * (config/features.php): off, the category is not listed and its route
 * answers 404 like the module.
 */
enum SituationCategory: string
{
    case Overview = 'overview';
    case Workforce = 'workforce';
    case Structure = 'structure';
    case ServiceDelivery = 'service_delivery';
    case Complaints = 'complaints';
    case Feedback = 'feedback';
    case TipOffs = 'tip_offs';
    case DailyOperations = 'daily_operations';
    case Performance = 'performance';
    case Movement = 'movement';
    case FieldWork = 'field_work';
    case ServiceMonitoring = 'service_monitoring';
    case Cafeteria = 'cafeteria';
    case Transport = 'transport';
    case Cases = 'cases';
    case CriticalAttention = 'critical_attention';
    case Trends = 'trends';
    case DataQuality = 'data_quality';

    /** The grant that opens the category, on top of situation_room.view. */
    public function permission(): string
    {
        return match ($this) {
            self::Overview, self::Structure, self::CriticalAttention, self::Trends, self::DataQuality => SituationPermissions::VIEW,
            self::Workforce, self::Movement => SituationPermissions::WORKFORCE,
            self::ServiceDelivery, self::Complaints, self::Feedback, self::TipOffs => SituationPermissions::PUBLIC_VOICE,
            self::DailyOperations, self::FieldWork, self::ServiceMonitoring => SituationPermissions::OPERATIONS,
            self::Performance => SituationPermissions::PERFORMANCE,
            self::Cafeteria => SituationPermissions::CAFETERIA,
            self::Transport => SituationPermissions::TRANSPORT,
            self::Cases => SituationPermissions::CASES,
        };
    }

    /** The launcher's group heading. */
    public function section(): string
    {
        return match ($this) {
            self::Overview, self::CriticalAttention, self::Trends => 'command',
            self::Workforce, self::Structure, self::Movement => 'people',
            self::ServiceDelivery, self::Complaints, self::Feedback, self::TipOffs => 'public_voice',
            self::DailyOperations, self::Performance, self::FieldWork, self::ServiceMonitoring, self::Cafeteria, self::Transport => 'operations',
            self::Cases, self::DataQuality => 'governance',
        };
    }

    /** Icon name from resources/js/Components/NavIcons.tsx. */
    public function icon(): string
    {
        return match ($this) {
            self::Overview => 'LayoutDashboard',
            self::Workforce => 'Users',
            self::Structure => 'NetworkIcon',
            self::ServiceDelivery => 'HandshakeIcon',
            self::Complaints => 'MessageSquareIcon',
            self::Feedback => 'StarIcon',
            self::TipOffs => 'MegaphoneIcon',
            self::DailyOperations => 'ClipboardListIcon',
            self::Performance => 'TrendingUpIcon',
            self::Movement => 'ArrowLeftRightIcon',
            self::FieldWork => 'MapPinned',
            self::ServiceMonitoring => 'ClipboardCheckIcon',
            self::Cafeteria => 'ReceiptTextIcon',
            self::Transport => 'BusIcon',
            self::Cases => 'ScaleIcon',
            self::CriticalAttention => 'AlertTriangle',
            self::Trends => 'ActivityIcon',
            self::DataQuality => 'BadgeCheckIcon',
        };
    }

    /** Whether the category may be shown on a wall display (aggregates only, never identities). */
    public function wallEligible(): bool
    {
        return in_array($this, [self::Overview, self::Workforce, self::Complaints, self::Feedback, self::TipOffs, self::DailyOperations, self::FieldWork], true);
    }

    public function visibleTo(User $user): bool
    {
        if ($this === self::Transport && ! Features::enabled('transport')) {
            return false;
        }
        if ($this === self::ServiceMonitoring && ! Features::enabled('service_monitoring')) {
            return false;
        }

        return $user->can(SituationPermissions::VIEW) && $user->can($this->permission());
    }

    /** @return list<self> */
    public static function visibleFor(User $user): array
    {
        return array_values(array_filter(self::cases(), fn (self $category): bool => $category->visibleTo($user)));
    }

    /** @return array{key: string, section: string, icon: string, wall: bool, href: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->value,
            'section' => $this->section(),
            'icon' => $this->icon(),
            'wall' => $this->wallEligible(),
            'href' => route('situation-room.category', $this->value),
        ];
    }
}
