<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\ReviewComment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<ReviewComment> */
class ReviewCommentFactory extends Factory
{
    protected $model = ReviewComment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'section' => 2,
            'body' => 'The waiting-area capacity looks too high.',
        ];
    }
}
