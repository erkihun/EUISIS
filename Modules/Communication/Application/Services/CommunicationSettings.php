<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Modules\Communication\Domain\Enums\CommunicationScope;

/**
 * Typed reader for the `communication` settings group. Every chat rule the
 * specification lists comes from here; none is hard-coded. A blank retention
 * means "keep": nothing is deleted without a configured policy.
 */
final class CommunicationSettings
{
    public const GROUP = SystemSettingsRegistry::GROUP_COMMUNICATION;

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->bool('communication_enabled', true);
    }

    public function directoryScope(): CommunicationScope
    {
        return CommunicationScope::tryFrom((string) $this->settings->get(self::GROUP, 'communication_scope_default', 'OWN_ORGANIZATION'))
            ?? CommunicationScope::OwnOrganization;
    }

    public function allowEmployeeGroups(): bool
    {
        return $this->bool('allow_employee_groups', true);
    }

    public function allowEmployeeChannels(): bool
    {
        return $this->bool('allow_employee_channels', false);
    }

    public function allowEditing(): bool
    {
        return $this->bool('allow_message_editing', true);
    }

    public function editWindowMinutes(): int
    {
        return $this->bounded('message_edit_window_minutes', 15, 0, 10080);
    }

    public function allowDeletion(): bool
    {
        return $this->bool('allow_message_deletion', true);
    }

    public function deleteWindowMinutes(): int
    {
        return $this->bounded('message_delete_window_minutes', 60, 0, 10080);
    }

    public function allowForwarding(): bool
    {
        return $this->bool('allow_message_forwarding', true);
    }

    public function allowReadReceipts(): bool
    {
        return $this->bool('allow_read_receipts', true);
    }

    public function allowPresence(): bool
    {
        return $this->bool('allow_presence', true);
    }

    public function allowLastSeen(): bool
    {
        return $this->bool('allow_last_seen', true);
    }

    public function maxAttachmentKb(): int
    {
        return $this->bounded('max_attachment_size_kb', 10240, 64, 51200);
    }

    /** @return list<string> lower-case extensions */
    public function allowedAttachmentTypes(): array
    {
        $value = $this->settings->get(self::GROUP, 'allowed_attachment_types', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg']);
        $list = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

        // Never executable or browser-rendered markup, whatever the setting says.
        return array_values(array_diff(array_map(fn ($v) => strtolower(trim((string) $v)), $list), ['html', 'htm', 'svg', 'js', 'mjs', 'xhtml', 'php', 'exe', 'bat', 'cmd', 'sh', 'ps1']));
    }

    public function scanAttachments(): bool
    {
        return $this->bool('enable_attachment_scanning', false);
    }

    public function retentionDays(): ?int
    {
        return $this->nullableInt('retention_days');
    }

    public function announcementRetentionDays(): ?int
    {
        return $this->nullableInt('announcement_retention_days');
    }

    public function emailUrgentAnnouncements(): bool
    {
        return $this->bool('email_urgent_announcements', false);
    }

    public function smsEmergencyAnnouncements(): bool
    {
        return $this->bool('sms_emergency_announcements', false);
    }

    /** Longest investigation (break-glass) access an approver may grant. */
    public function breakGlassMaxHours(): int
    {
        return $this->bounded('break_glass_max_hours', 24, 1, 72);
    }

    /** Seconds since the last heartbeat within which someone counts as online. */
    public function onlineWindowSeconds(): int
    {
        return 120;
    }

    private function bool(string $key, bool $default): bool
    {
        return filter_var($this->settings->get(self::GROUP, $key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    private function bounded(string $key, int $default, int $min, int $max): int
    {
        $value = $this->settings->get(self::GROUP, $key, $default);

        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->settings->get(self::GROUP, $key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
