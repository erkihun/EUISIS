<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Replaces the ambiguous five-point "Waiting time" rating with a clear
 * duration choice for the currently published organization questionnaire.
 *
 * Questionnaire versions are immutable once people have responded.  This
 * migration therefore clones the current published form, changes only this
 * question in the clone, then publishes the clone and retires its source.
 * Historic submissions keep their original question and rating answer.
 */
return new class extends Migration
{
    private const FORM_CODE = 'ORGANIZATION_SERVICE_FEEDBACK';

    public function up(): void
    {
        DB::transaction(function (): void {
            $source = DB::table('public_feedback_question_sets')
                ->where('code', self::FORM_CODE)
                ->where('status', 'published')
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                return;
            }

            $waitingQuestion = DB::table('public_feedback_questions')
                ->where('question_set_id', $source->id)
                ->where('code', 'waiting_time')
                ->first();

            if ($waitingQuestion === null || $waitingQuestion->question_type === 'single_choice') {
                return;
            }

            $now = now();
            $newSetId = (string) Str::uuid();
            $newVersion = (int) DB::table('public_feedback_question_sets')
                ->where('code', self::FORM_CODE)
                ->max('version_no') + 1;

            DB::table('public_feedback_question_sets')->insert([
                'id' => $newSetId,
                'code' => $source->code,
                'version_no' => $newVersion,
                'name_en' => $source->name_en,
                'name_am' => $source->name_am,
                'status' => 'draft',
                'published_at' => null,
                'published_by' => $source->published_by,
                'retired_at' => null,
                'created_by' => $source->created_by,
                'updated_by' => $source->updated_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $questions = DB::table('public_feedback_questions')
                ->where('question_set_id', $source->id)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get();

            foreach ($questions as $question) {
                $newQuestionId = (string) Str::uuid();
                $isWaitingTime = $question->code === 'waiting_time';

                DB::table('public_feedback_questions')->insert([
                    'id' => $newQuestionId,
                    'question_set_id' => $newSetId,
                    'code' => $question->code,
                    'section' => $question->section,
                    'question_type' => $isWaitingTime ? 'single_choice' : $question->question_type,
                    'label_en' => $isWaitingTime ? 'About how long did you wait before you were served?' : $question->label_en,
                    'label_am' => $isWaitingTime ? 'አገልግሎት ከመሰጠትዎ በፊት በግምት ስንት ጊዜ ጠበቁ?' : $question->label_am,
                    'help_en' => $isWaitingTime ? 'Choose the closest waiting time.' : $question->help_en,
                    'help_am' => $isWaitingTime ? 'በጣም የሚቀርበውን የጥበቃ ጊዜ ይምረጡ።' : $question->help_am,
                    'is_required' => $question->is_required,
                    'submission_types' => $question->submission_types,
                    'sort_order' => $question->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($isWaitingTime) {
                    foreach ($this->waitingTimeOptions() as $index => $option) {
                        DB::table('public_feedback_question_options')->insert([
                            'id' => (string) Str::uuid(),
                            'question_id' => $newQuestionId,
                            'code' => $option['code'],
                            'label_en' => $option['label_en'],
                            'label_am' => $option['label_am'],
                            'sort_order' => ($index + 1) * 10,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    continue;
                }

                $options = DB::table('public_feedback_question_options')
                    ->where('question_id', $question->id)
                    ->orderBy('sort_order')
                    ->orderBy('code')
                    ->get();

                foreach ($options as $option) {
                    DB::table('public_feedback_question_options')->insert([
                        'id' => (string) Str::uuid(),
                        'question_id' => $newQuestionId,
                        'code' => $option->code,
                        'label_en' => $option->label_en,
                        'label_am' => $option->label_am,
                        'sort_order' => $option->sort_order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('public_feedback_question_sets')->where('id', $source->id)->update([
                'status' => 'retired',
                'retired_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('public_feedback_question_sets')->where('id', $newSetId)->update([
                'status' => 'published',
                'published_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: submitted answers may now reference the new version.
    }

    /** @return list<array{code: string, label_en: string, label_am: string}> */
    private function waitingTimeOptions(): array
    {
        return [
            ['code' => 'under_15_minutes', 'label_en' => 'Less than 15 minutes', 'label_am' => 'ከ15 ደቂቃ በታች'],
            ['code' => '15_to_30_minutes', 'label_en' => '15–30 minutes', 'label_am' => '15–30 ደቂቃ'],
            ['code' => '31_to_60_minutes', 'label_en' => '31–60 minutes', 'label_am' => '31–60 ደቂቃ'],
            ['code' => 'over_60_minutes', 'label_en' => 'More than 1 hour', 'label_am' => 'ከ1 ሰዓት በላይ'],
            ['code' => 'not_sure', 'label_en' => 'Not sure', 'label_am' => 'እርግጠኛ አይደለሁም'],
        ];
    }
};
