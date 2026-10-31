<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

/**
 * The proposed first draft of the organization feedback questionnaire, from
 * the concepts of the paper service-feedback form: rated service-experience
 * items, whether the visitor got the service, what should be improved, and —
 * on a Complaint — the outcome asked for.
 *
 * It is only ever loaded as a DRAFT, which an authorised administrator must
 * review and publish (NEEDS_POLICY_DECISION: the item list and wording are a
 * proposal, not approved policy; NEEDS_LANGUAGE_REVIEW: the Amharic wording).
 * Nothing here reaches the public until a person publishes it.
 *
 * The rating scale is the platform's existing five-point satisfaction scale
 * (very dissatisfied … very satisfied), already used by the Feedback rating.
 */
final class PublicFeedbackQuestionnaireTemplate
{
    /** @return array{name_en: string, name_am: string} */
    public static function name(): array
    {
        return ['name_en' => 'Organization service feedback', 'name_am' => 'የተቋም አገልግሎት አስተያየት'];
    }

    /** @return list<array<string, mixed>> */
    public static function questions(): array
    {
        $feedback = ['feedback'];

        return [
            self::rating('information_clarity', 'Clarity of the information you were given', 'የተሰጠዎት መረጃ ግልጽነት', $feedback),
            self::rating('staff_courtesy', 'Courtesy and respect of the staff', 'የሠራተኞች ትሕትና እና አክብሮት', $feedback),
            [
                'code' => 'waiting_time',
                'section' => 'experience',
                'question_type' => 'single_choice',
                'label_en' => 'About how long did you wait before you were served?',
                'label_am' => 'አገልግሎት ከመሰጠትዎ በፊት በግምት ስንት ጊዜ ጠበቁ?',
                'help_en' => 'Choose the closest waiting time.',
                'help_am' => 'በጣም የሚቀርበውን የጥበቃ ጊዜ ይምረጡ።',
                'is_required' => false,
                'submission_types' => $feedback,
                'options' => self::waitingTimeOptions(),
            ],
            self::rating('service_speed', 'Speed of the service once you were served', 'አገልግሎቱ የተሰጠበት ፍጥነት', $feedback),
            self::rating('process_simplicity', 'Simplicity of the procedure and requirements', 'የአሠራሩ እና የሚጠየቁ መስፈርቶች ቀላልነት', $feedback),
            self::rating('fairness', 'Fair and equal treatment', 'ፍትሐዊ እና እኩል አያያዝ', $feedback),
            self::rating('accessibility', 'Ease of finding and reaching the service', 'አገልግሎቱን ለማግኘት እና ለመድረስ ያለው ምቹነት', $feedback),
            [
                'code' => 'service_obtained',
                'section' => 'details',
                'question_type' => 'yes_no',
                'label_en' => 'Did you get the service you came for?',
                'label_am' => 'የመጡበትን አገልግሎት አግኝተዋል?',
                'help_en' => null,
                'help_am' => null,
                'is_required' => false,
                'submission_types' => ['feedback', 'complaint'],
                'options' => [],
            ],
            [
                'code' => 'improvement',
                'section' => 'details',
                'question_type' => 'long_text',
                'label_en' => 'What should be improved?',
                'label_am' => 'ምን መሻሻል አለበት?',
                'help_en' => 'Your recommendation for a better service.',
                'help_am' => 'አገልግሎቱ የተሻለ እንዲሆን የሚሰጡት ምክረ ሐሳብ።',
                'is_required' => false,
                'submission_types' => $feedback,
                'options' => [],
            ],
            [
                'code' => 'desired_resolution',
                'section' => 'details',
                'question_type' => 'long_text',
                'label_en' => 'What outcome are you asking for?',
                'label_am' => 'ምን ዓይነት መፍትሔ ይጠብቃሉ?',
                'help_en' => null,
                'help_am' => null,
                'is_required' => false,
                'submission_types' => ['complaint'],
                'options' => [],
            ],
        ];
    }

    /**
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    private static function rating(string $code, string $en, string $am, array $types): array
    {
        return [
            'code' => $code,
            'section' => 'experience',
            'question_type' => 'rating',
            'label_en' => $en,
            'label_am' => $am,
            'help_en' => null,
            'help_am' => null,
            'is_required' => false,
            'submission_types' => $types,
            'options' => [],
        ];
    }

    /** @return list<array{code: string, label_en: string, label_am: string}> */
    private static function waitingTimeOptions(): array
    {
        return [
            ['code' => 'under_15_minutes', 'label_en' => 'Less than 15 minutes', 'label_am' => 'ከ15 ደቂቃ በታች'],
            ['code' => '15_to_30_minutes', 'label_en' => '15–30 minutes', 'label_am' => '15–30 ደቂቃ'],
            ['code' => '31_to_60_minutes', 'label_en' => '31–60 minutes', 'label_am' => '31–60 ደቂቃ'],
            ['code' => 'over_60_minutes', 'label_en' => 'More than 1 hour', 'label_am' => 'ከ1 ሰዓት በላይ'],
            ['code' => 'not_sure', 'label_en' => 'Not sure', 'label_am' => 'እርግጠኛ አይደለሁም'],
        ];
    }
}
