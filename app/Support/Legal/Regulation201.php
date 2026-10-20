<?php

declare(strict_types=1);

namespace App\Support\Legal;

/**
 * Time limits of Addis Ababa City Administration Regulation No. 201/2018 on
 * civil-servant discipline, grievance and appeal procedure (in force from
 * Tir 8, 2018 E.C. = 16 January 2026). Source: docs/ደንብ_ቁጥር_201_2018_….pdf.
 *
 * Each rule keeps the unit the regulation states: "ተከታታይ የሥራ ቀናት"
 * (consecutive working days) is working_days, "ቀናት" alone is calendar_days and
 * "ወር" is months. Nothing here is an extension or a policy choice; rules the
 * text leaves unclear are listed in docs/regulation-201-2018-traceability.md
 * as NEEDS_DECISION instead of being guessed.
 */
final class Regulation201
{
    public const EFFECTIVE_FROM = '2026-01-16';

    /** The value of governing_regulation on a grievance or disciplinary case it governs. */
    public const CODE = '201/2018';

    /** Art. 56: proceedings begun before EFFECTIVE_FROM continue under the repealed regulation. */
    public const PREDECESSOR = '26/2002';

    public const WORKING_DAYS = 'working_days';

    public const CALENDAR_DAYS = 'calendar_days';

    public const MONTHS = 'months';

    // ── Discipline ───────────────────────────────────────────────────────────
    public const DISCIPLINE_AMENDMENT = 'discipline.amendment';

    public const DISCIPLINE_SUMMONS_NOTICE = 'discipline.summons_notice';

    public const DISCIPLINE_SUMMONS_POSTING = 'discipline.summons_posting';

    public const DISCIPLINE_INVESTIGATION = 'discipline.investigation';

    public const DISCIPLINE_INVESTIGATION_EXTENDED = 'discipline.investigation_extended';

    public const DISCIPLINE_HEAD_DECISION = 'discipline.head_decision';

    public const DISCIPLINE_DECISION_DELIVERY = 'discipline.decision_delivery';

    public const DISCIPLINE_DECISION_POSTING = 'discipline.decision_posting';

    public const DISCIPLINE_PENALTY_EFFECTIVE = 'discipline.penalty_effective';

    public const DISCIPLINE_SUSPENSION_MAX = 'discipline.suspension_max';

    // ── Employee grievance ───────────────────────────────────────────────────
    public const GRIEVANCE_COMMITTEE_FILING = 'grievance.committee_filing';

    public const GRIEVANCE_FORCE_MAJEURE_FILING = 'grievance.force_majeure_filing';

    public const GRIEVANCE_COMMITTEE_REPORT = 'grievance.committee_report';

    public const GRIEVANCE_HEAD_DECISION = 'grievance.head_decision';

    public const GRIEVANCE_DECISION_DELIVERY = 'grievance.decision_delivery';

    // ── Appeal / Administrative Tribunal ─────────────────────────────────────
    public const APPEAL_RECORD_COPY = 'appeal.record_copy';

    public const APPEAL_FILING = 'appeal.filing';

    public const APPEAL_LATE_PERMISSION = 'appeal.late_permission';

    public const APPEAL_LATE_ABSOLUTE = 'appeal.late_absolute';

    public const APPEAL_FILING_AFTER_PERMISSION = 'appeal.filing_after_permission';

    public const APPEAL_SET_ASIDE_ABSENCE = 'appeal.set_aside_absence';

    public const APPEAL_TRIBUNAL_DECISION = 'appeal.tribunal_decision';

    public const APPEAL_DECISION_COPY = 'appeal.decision_copy';

    public const APPEAL_REMAND_RECONSIDERATION = 'appeal.remand_reconsideration';

    public const APPEAL_EXECUTION = 'appeal.execution';

    /**
     * @return array<string, array{article: string, amount: int, unit: string, roll_forward: bool, label_en: string, label_am: string}>
     */
    public static function rules(): array
    {
        $rule = static fn (string $article, int $amount, string $unit, string $en, string $am, bool $rollForward = false): array => [
            'article' => $article,
            'amount' => $amount,
            'unit' => $unit,
            'roll_forward' => $rollForward,
            'label_en' => $en,
            'label_am' => $am,
        ];

        return [
            self::DISCIPLINE_AMENDMENT => $rule('Art. 16(5)', 5, self::CALENDAR_DAYS,
                'Amended charge or response to be filed', 'የተሻሻለ ክስ ወይም መልስ የሚቀርብበት'),
            self::DISCIPLINE_SUMMONS_NOTICE => $rule('Art. 18(2)', 10, self::WORKING_DAYS,
                'Summons must reach the accused before the hearing', 'መጥሪያው ከችሎቱ በፊት ለተከሳሹ መድረስ ያለበት'),
            self::DISCIPLINE_SUMMONS_POSTING => $rule('Art. 18(3)', 10, self::WORKING_DAYS,
                'Posted summons period', 'የተለጠፈ መጥሪያ የሚቆይበት'),
            self::DISCIPLINE_INVESTIGATION => $rule('Art. 27(1)', 1, self::MONTHS,
                'Committee investigation and recommendation', 'የኮሚቴ ምርመራና የውሳኔ ሀሳብ'),
            self::DISCIPLINE_INVESTIGATION_EXTENDED => $rule('Art. 27(1)', 2, self::MONTHS,
                'Investigation where additional evidence is collected', 'ተጨማሪ ማስረጃ የሚሰበሰብበት ምርመራ'),
            self::DISCIPLINE_HEAD_DECISION => $rule('Art. 28(1)', 5, self::WORKING_DAYS,
                'Head of institution decision on the recommendation', 'የበላይ ኃላፊው ውሳኔ'),
            self::DISCIPLINE_DECISION_DELIVERY => $rule('Art. 28(3)', 5, self::WORKING_DAYS,
                'Written decision delivered to the employee', 'ውሳኔው ለሠራተኛው በጽሑፍ የሚሰጥበት'),
            self::DISCIPLINE_DECISION_POSTING => $rule('Art. 28(5)', 10, self::CALENDAR_DAYS,
                'Posted decision period', 'የተለጠፈ ውሳኔ የሚቆይበት'),
            self::DISCIPLINE_PENALTY_EFFECTIVE => $rule('Art. 29(1)', 30, self::CALENDAR_DAYS,
                'Salary fine or demotion takes effect (unless appealed)', 'የደመወዝ ቅጣት ወይም ዝቅታ ተፈጻሚ የሚሆንበት'),
            self::DISCIPLINE_SUSPENSION_MAX => $rule('Art. 12(4)', 3, self::MONTHS,
                'Maximum suspension from work and salary', 'ከሥራና ደመወዝ የሚታገድበት ከፍተኛ ጊዜ'),

            self::GRIEVANCE_COMMITTEE_FILING => $rule('Art. 33(1)', 10, self::WORKING_DAYS,
                'Grievance to the committee after the unresolved written submission', 'ያልተፈታ ቅሬታ ለኮሚቴ የሚቀርብበት'),
            self::GRIEVANCE_FORCE_MAJEURE_FILING => $rule('Art. 33(2)', 10, self::WORKING_DAYS,
                'Late grievance after a force-majeure cause ends', 'ከአቅም በላይ ምክንያት ከተነሳ በኋላ የሚቀርብበት'),
            self::GRIEVANCE_COMMITTEE_REPORT => $rule('Art. 34(3)', 15, self::WORKING_DAYS,
                'Grievance committee report to the head', 'የቅሬታ አጣሪ ኮሚቴ ሪፖርት'),
            self::GRIEVANCE_HEAD_DECISION => $rule('Art. 35(1)', 10, self::WORKING_DAYS,
                'Head of institution decision on the grievance report', 'የበላይ ኃላፊው በቅሬታ ሪፖርት ላይ የሚወስንበት'),
            self::GRIEVANCE_DECISION_DELIVERY => $rule('Art. 35(2)', 5, self::WORKING_DAYS,
                'Written grievance decision delivered to the employee', 'የቅሬታ ውሳኔ በጽሑፍ የሚሰጥበት'),

            self::APPEAL_RECORD_COPY => $rule('Art. 37(2)', 5, self::WORKING_DAYS,
                'Certified committee record copy to the employee', 'የተረጋገጠ የኮሚቴ መዝገብ ግልባጭ የሚሰጥበት'),
            self::APPEAL_FILING => $rule('Art. 38(1), 38(4)', 30, self::CALENDAR_DAYS,
                'Appeal to the Administrative Tribunal', 'ለአስተዳደር ፍርድ ቤት ይግባኝ የሚቀርብበት', true),
            self::APPEAL_LATE_PERMISSION => $rule('Art. 39(1)', 10, self::WORKING_DAYS,
                'Leave to appeal late after a force-majeure cause ends', 'ዘግይቶ ይግባኝ ለማቅረብ ፈቃድ የሚጠየቅበት'),
            self::APPEAL_LATE_ABSOLUTE => $rule('Art. 39(2)', 3, self::MONTHS,
                'Absolute limit for dismissal or demotion appeals', 'የስንብትና የዝቅታ ይግባኝ ፍጹም የጊዜ ገደብ'),
            self::APPEAL_FILING_AFTER_PERMISSION => $rule('Art. 39(6)', 5, self::WORKING_DAYS,
                'Appeal filed after leave is granted', 'ፈቃድ ከተሰጠ በኋላ ይግባኝ የሚቀርብበት'),
            self::APPEAL_SET_ASIDE_ABSENCE => $rule('Art. 44(3)-(4)', 15, self::CALENDAR_DAYS,
                'Application to set aside an order made in absence', 'በሌለበት የተሰጠ ትዕዛዝ እንዲነሳ የሚጠየቅበት'),
            self::APPEAL_TRIBUNAL_DECISION => $rule('Art. 52(1)', 30, self::CALENDAR_DAYS,
                'Administrative Tribunal decision', 'የአስተዳደር ፍርድ ቤት ውሳኔ'),
            self::APPEAL_DECISION_COPY => $rule('Art. 52(4)', 5, self::WORKING_DAYS,
                'Tribunal decision copy to the parties', 'የውሳኔ ግልባጭ ለተከራካሪዎች የሚሰጥበት'),
            self::APPEAL_REMAND_RECONSIDERATION => $rule('Art. 53(2)', 15, self::WORKING_DAYS,
                'Institution reconsideration after remand', 'ከተመለሰ በኋላ መሥሪያ ቤቱ እንደገና የሚወስንበት'),
            self::APPEAL_EXECUTION => $rule('Art. 54(1)', 10, self::CALENDAR_DAYS,
                'Institution executes the Tribunal decision', 'መሥሪያ ቤቱ የፍርድ ቤቱን ውሳኔ የሚፈጽምበት'),
        ];
    }

    /** @return array{article: string, amount: int, unit: string, roll_forward: bool, label_en: string, label_am: string} */
    public static function rule(string $key): array
    {
        return self::rules()[$key] ?? throw new \InvalidArgumentException("Unknown Regulation 201/2018 rule [{$key}].");
    }
}
