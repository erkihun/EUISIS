<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Assessment\AssessmentFormImporter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Imports assessment forms from definition files (default: the official
 * behavioural competency forms in database/seeders/data/assessment-forms).
 * Forms are created as drafts; --publish publishes those that pass the
 * builder's publishing checks. Existing form codes are skipped.
 */
class ImportAssessmentForms extends Command
{
    protected $signature = 'assessments:import-forms
        {paths?* : Definition files (default: database/seeders/data/assessment-forms/*.json)}
        {--publish : Publish each imported form that passes the publishing checks}
        {--actor= : Email of the user recorded as author (default: the first active Super Admin)}';

    protected $description = 'Create assessment forms from definition files through the form builder';

    public function handle(AssessmentFormImporter $importer): int
    {
        $actor = $this->actor();
        if ($actor === null) {
            $this->error('No author found: pass --actor=<email> of an existing user.');

            return self::FAILURE;
        }

        $paths = $this->argument('paths') ?: glob(database_path('seeders/data/assessment-forms/*.json')) ?: [];
        if ($paths === []) {
            $this->warn('No definition files found.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($paths as $path) {
            try {
                $definition = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                $result = $importer->import($actor, (array) $definition, (bool) $this->option('publish'));
            } catch (JsonException|ValidationException $exception) {
                $failed = true;
                $details = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage();
                $this->error(basename($path).': not imported. '.$details);

                continue;
            }

            $code = $result['form']->code;
            match (true) {
                $result['status'] === 'exists' => $this->line("{$code}: already exists, left unchanged."),
                $result['published'] => $this->info("{$code}: imported and published."),
                default => $this->info("{$code}: imported as a draft."),
            };
            foreach ($result['problems'] as $problem) {
                $this->line("  - to publish: {$problem}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function actor(): ?User
    {
        $email = $this->option('actor');
        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return User::query()->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('name', 'Super Admin'))
            ->orderBy('id')->first();
    }
}
