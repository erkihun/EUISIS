<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

/**
 * Deterministic routing for anonymous questions: a quick-action key wins;
 * otherwise keyword sets (English and Amharic) choose which public tools
 * run. Requests for anything internal — employees' details, complaint
 * lists, system errors, administrator access — are recognised and answered
 * with a refusal plus the public alternatives; no tool exists for them, so
 * the refusal is a courtesy, not the control (§59).
 */
final class PublicAssistantIntentRouter
{
    public const DENIED = 'DENIED';

    /** @var array<string, list<string>> quick action → tools */
    private const ACTIONS = [
        'how_to_use' => ['public_page_help'],
        'submit_feedback' => ['public_feedback_help'],
        'submit_suggestion' => ['public_feedback_help'],
        'submit_complaint' => ['public_feedback_help'],
        'track_submission' => ['public_tracking_help'],
        'verify_employee' => ['public_page_help'],
        'organization_services' => ['public_organization_information'],
        'contact' => ['public_support_handoff'],
        'public_help' => ['public_knowledge_search', 'public_safe_navigation'],
    ];

    private const DENY_TERMS = ['phone number of', 'employee phone', "employee's", 'employee’s', 'his phone', 'her phone', 'private phone', 'personal phone', 'home phone', 'internal employee id', 'employee record', 'employees in', 'all employees', 'list of employees', 'find employee', 'employee by', 'salary', 'national id', 'date of birth', 'home address',
        'unresolved complaints', 'all complaints', 'complaints for', 'complaint list', 'internal note', 'who handles', 'investigator',
        'database', 'server', 'error log', 'logs', 'queue', 'incident', 'stack trace', 'system prompt', 'ignore all rules', 'ignore your rules', 'act as', 'super admin', 'administrator', 'admin panel', 'situation room', 'workforce', 'analytics', 'performance score', 'discipline', 'grievance',
        'የሠራተኛ ስልክ', 'ሁሉንም ሠራተኞች', 'የሠራተኞች ዝርዝር', 'ደመወዝ', 'ብሔራዊ መታወቂያ', 'የትውልድ ቀን', 'ያልተፈቱ ቅሬታዎች', 'ሁሉንም ቅሬታዎች', 'የመረጃ ቋት', 'አስተዳዳሪ', 'ሱፐር አድሚን'];

    private const FEEDBACK_TERMS = ['feedback', 'suggestion', 'tip-off', 'tip off', 'complaint', 'complain', 'submit', 'form', 'what should i write', 'difference between', 'ግብረ መልስ', 'አስተያየት', 'ጥቆማ', 'ቅሬታ', 'ማስገባት', 'ቅጽ'];

    private const TRACKING_TERMS = ['track', 'tracking', 'reference', 'status of my', 'my submission', 'receipt', 'ref-', 'መከታተል', 'ማጣቀሻ', 'ደረሰኝ', 'የእኔ ማመልከቻ'];

    private const SERVICE_TERMS = ['service', 'services', 'requirement', 'how do i get', 'how to get', 'apply for', 'አገልግሎት', 'አገልግሎቶች', 'መስፈርት'];

    private const ORGANIZATION_TERMS = ['this organization', 'this institution', 'this office', 'organization provide', 'what can i do now', 'scanned', 'ይህ ተቋም', 'ይህ ጽሕፈት ቤት', 'ተቃኘ'];

    private const CONTACT_TERMS = ['contact', 'phone', 'email', 'call', 'reach', 'office hours', 'አድራሻ', 'ስልክ', 'ኢሜይል', 'ይደውሉ'];

    private const STATUS_TERMS = ['site having a problem', 'is the site', 'is the system', 'down', 'not working', 'outage', 'ችግር አለ', 'አይሰራም', 'አይሠራም'];

    private const VERIFY_TERMS = ['verify', 'verification', 'employee id', 'id card', 'id checker', 'genuine', 'otp', 'ማረጋገጥ', 'መታወቂያ', 'ማረጋገጫ'];

    private const HELP_TERMS = ['how do i use', 'how to use', 'what is this page', 'help me', 'what can i do', 'explain', 'እንዴት እጠቀማለሁ', 'ይህ ገጽ ምንድን ነው', 'እርዳኝ', 'አብራራ'];

    /** @return array{mode: string, tools: list<string>} */
    public function route(?string $action, string $message, PublicAssistantContext $context): array
    {
        $text = mb_strtolower(trim($message));

        if ($action !== null && isset(self::ACTIONS[$action])) {
            $tools = self::ACTIONS[$action];
            if ($action === 'verify_employee' && ! in_array($context->kind(), ['verify', 'id_checker'], true)) {
                $tools = ['public_page_help', 'public_safe_navigation'];
            }

            return ['mode' => strtoupper($action), 'tools' => $tools];
        }

        if ($this->has($text, self::DENY_TERMS)) {
            return ['mode' => self::DENIED, 'tools' => []];
        }

        return match (true) {
            $this->has($text, self::STATUS_TERMS) => ['mode' => 'STATUS', 'tools' => $context->can('status') ? ['public_status', 'public_support_handoff'] : ['public_support_handoff']],
            $this->has($text, self::TRACKING_TERMS) => ['mode' => 'TRACKING', 'tools' => ['public_tracking_help']],
            $this->has($text, self::FEEDBACK_TERMS) => ['mode' => 'FEEDBACK', 'tools' => ['public_feedback_help']],
            $this->has($text, self::ORGANIZATION_TERMS) && $context->isQrPage() => ['mode' => 'ORGANIZATION', 'tools' => ['public_organization_information']],
            $this->has($text, self::CONTACT_TERMS) => ['mode' => 'CONTACT', 'tools' => $context->isQrPage() ? ['public_organization_information', 'public_support_handoff'] : ['public_support_handoff']],
            $this->has($text, self::VERIFY_TERMS) => ['mode' => 'VERIFY', 'tools' => ['public_page_help', 'public_safe_navigation']],
            $this->has($text, self::SERVICE_TERMS) => ['mode' => 'SERVICES', 'tools' => $context->isQrPage() ? ['public_organization_information', 'public_service_information'] : ['public_service_information']],
            $this->has($text, self::HELP_TERMS) => ['mode' => 'HELP', 'tools' => ['public_page_help']],
            default => ['mode' => 'KNOWLEDGE', 'tools' => ['public_knowledge_search']],
        };
    }

    /** @param  list<string>  $terms */
    private function has(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
