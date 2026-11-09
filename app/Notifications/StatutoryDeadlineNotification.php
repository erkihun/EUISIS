<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Legal\Regulation201;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A Regulation 201/2018 deadline the institution must meet is close or has
 * passed. Carries only the case number, the rule and the due date — never
 * the content of the case. Rendered at read time in the reader's language.
 */
class StatutoryDeadlineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $ruleKey,
        public string $caseNumber,
        public string $dueOn,
        public ?string $url,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'statutory_deadline', 'kind' => $this->kind, 'rule_key' => $this->ruleKey, 'case_number' => $this->caseNumber, 'due_on' => $this->dueOn, 'url' => $this->url];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $rule = Regulation201::rules()[(string) ($data['rule_key'] ?? '')] ?? null;
        $label = $rule === null ? (string) ($data['rule_key'] ?? '') : ($locale === 'am' ? $rule['label_am'] : $rule['label_en']);
        $kind = ($data['kind'] ?? '') === 'overdue' ? 'overdue' : 'due_soon';

        return [
            'title' => (string) __('common.statutory_deadline.title', [], $locale),
            'message' => (string) __("common.statutory_deadline.{$kind}", [
                'rule' => $label,
                'case' => (string) ($data['case_number'] ?? ''),
                'date' => (string) ($data['due_on'] ?? ''),
            ], $locale),
        ];
    }
}
