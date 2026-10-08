<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AssessmentForm;
use App\Models\AssessmentType;
use App\Models\User;
use App\Services\Assessment\AssessmentFormService;
use Illuminate\Database\Seeder;

/**
 * A synthetic, editable sample form for trying the assessment workflow:
 * a peer-rated behavioural form with three sections and a 4-point scale.
 *
 * It is starting data, not policy: no official form, wording or score
 * range is reproduced. Built through AssessmentFormService, so it passes the
 * same validation as a form made in the UI and is audited. Idempotent: an
 * existing form with the same code is left untouched.
 *
 *   php artisan db:seed --class=AssessmentSampleFormSeeder
 */
class AssessmentSampleFormSeeder extends Seeder
{
    public const CODE = 'SAMPLE-PEER-BEHAVIOUR';

    public function run(AssessmentFormService $forms): void
    {
        if (AssessmentForm::query()->where('code', self::CODE)->exists()) {
            $this->command?->info('Sample assessment form already exists: '.self::CODE);

            return;
        }

        $type = AssessmentType::query()->where('code', 'BEHAVIORAL_COMPETENCY')->first();
        $actor = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->orderBy('id')->first() ?? User::query()->orderBy('id')->first();
        if ($type === null || $actor === null) {
            $this->command?->warn('Skipped: run the migrations and create at least one user first.');

            return;
        }

        $form = $forms->create($actor, [
            'code' => self::CODE,
            'name_en' => 'Sample: Peer behavioural assessment',
            'name_am' => 'ናሙና፦ የአቻ የባህሪ ምዘና',
            'description_en' => 'Synthetic sample for trying the workflow. Edit or archive it; it is not an official form.',
            'description_am' => 'የሥራ ሂደቱን ለመሞከር የተዘጋጀ ናሙና። ማስተካከል ወይም በማህደር ማስቀመጥ ይችላሉ፤ ይፋዊ ቅጽ አይደለም።',
            'assessment_type_id' => $type->id,
        ]);

        $draft = $form->draftVersion();
        $forms->saveDraft($actor, $draft, $this->content());
        $forms->publish($actor, $draft->fresh());

        $this->command?->info('Published sample assessment form '.self::CODE.' (version 1).');
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        $sections = [
            ['TEAM', 'Teamwork and collaboration', 'የቡድን ሥራ እና ትብብር', [
                ['TEAM-1', 'Shares knowledge and information with colleagues', 'ዕውቀትና መረጃን ለሥራ ባልደረቦች ያካፍላል'],
                ['TEAM-2', 'Supports colleagues to meet shared goals', 'የጋራ ግቦችን ለማሳካት ባልደረቦችን ይደግፋል'],
                ['TEAM-3', 'Resolves disagreements respectfully', 'አለመግባባቶችን በአክብሮት ይፈታል'],
            ]],
            ['SERV', 'Service and integrity', 'አገልግሎት እና ታማኝነት', [
                ['SERV-1', 'Treats service users courteously and fairly', 'ተገልጋዮችን በትህትና እና በፍትሐዊነት ያስተናግዳል'],
                ['SERV-2', 'Uses public resources responsibly', 'የሕዝብ ሀብትን በኃላፊነት ይጠቀማል'],
            ]],
            ['DISC', 'Work discipline', 'የሥራ ዲሲፕሊን', [
                ['DISC-1', 'Completes assigned work on time', 'የተሰጠውን ሥራ በወቅቱ ያጠናቅቃል'],
                ['DISC-2', 'Follows procedures and keeps accurate records', 'አሠራሮችን ይከተላል፤ ትክክለኛ መዝገብ ይይዛል'],
            ]],
        ];
        // One 4-point scale for every criterion; administrators can change it in the builder.
        $scale = [
            ['Always', 'ሁልጊዜ', 'Consistently shows this behaviour.', 'ይህን ባህሪ ያለማቋረጥ ያሳያል።', 4],
            ['Usually', 'ብዙ ጊዜ', 'Shows this behaviour in most situations.', 'ይህን ባህሪ በአብዛኛው ሁኔታ ያሳያል።', 3],
            ['Sometimes', 'አንዳንድ ጊዜ', 'Shows this behaviour occasionally.', 'ይህን ባህሪ አልፎ አልፎ ያሳያል።', 2],
            ['Rarely', 'አልፎ አልፎ', 'Seldom shows this behaviour.', 'ይህን ባህሪ እምብዛም አያሳይም።', 1],
        ];
        $options = array_map(fn (array $o): array => ['label_en' => $o[0], 'label_am' => $o[1], 'description_en' => $o[2], 'description_am' => $o[3], 'score' => $o[4]], $scale);

        return [
            'name_en' => 'Sample: Peer behavioural assessment',
            'name_am' => 'ናሙና፦ የአቻ የባህሪ ምዘና',
            'instructions_en' => 'Rate each statement from what you observed during the assessment period. Choose the option that fits best.',
            'instructions_am' => 'በምዘና ወቅቱ ካስተዋሉት በመነሳት እያንዳንዱን መግለጫ ይመዝኑ። በተሻለ የሚገልጸውን አማራጭ ይምረጡ።',
            'scoring_method' => 'percent_of_max',
            'acknowledgement_required' => true,
            'review_required' => true,
            'sections' => array_map(function (array $section) use ($options): array {
                [$code, $en, $am, $criteria] = $section;
                $max = count($criteria) * 4;

                return [
                    'code' => $code, 'title_en' => $en, 'title_am' => $am, 'max_score' => $max, 'is_required' => true,
                    'criteria' => array_map(fn (array $c): array => [
                        'code' => $c[0], 'title_en' => $c[1], 'title_am' => $c[2], 'max_score' => 4, 'is_required' => true,
                        'comment_mode' => 'optional', 'evidence_mode' => 'disabled', 'options' => $options,
                    ], $criteria),
                ];
            }, $sections),
            'max_total_score' => array_sum(array_map(fn (array $s): int => count($s[3]) * 4, $sections)),
            'target_rules' => [['target_type' => 'everyone', 'effect' => 'include', 'priority' => 0, 'include_descendants' => true]],
            'evaluators' => [['evaluator_type' => 'peer', 'required_count' => 1, 'selection_method' => 'admin_selected', 'aggregation_method' => 'average', 'is_anonymous' => true, 'requires_review' => true]],
        ];
    }
}
