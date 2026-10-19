<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Models\IntelligenceKnowledgeDocument;
use App\Models\User;
use App\Services\Intelligence\Knowledge\KnowledgeDocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Imports the repository's Markdown documentation as `repo_doc` knowledge
 * documents (docs/intelligence-knowledge.md). One file at a time: a changed
 * file becomes a new draft version (re-chunked), an unchanged one is skipped,
 * and nothing outside the repository root is ever read.
 */
final class IntelligenceKnowledgeImport extends Command
{
    protected $signature = 'intelligence:knowledge-import
        {--path=docs : Folder of *.md files, relative to the repository root}
        {--approve : Approve imported and revised documents right away}
        {--classification=internal : Classification given to new documents}
        {--user= : Id of the user recorded as author and approver}';

    protected $description = 'Import Markdown documents from the repository into the Knowledge Assistant';

    public function handle(KnowledgeDocumentService $documents): int
    {
        $classification = (string) $this->option('classification');
        if (! in_array($classification, IntelligenceKnowledgeDocument::CLASSIFICATIONS, true)) {
            $this->error('Unknown classification; use one of '.implode(', ', IntelligenceKnowledgeDocument::CLASSIFICATIONS).'.');

            return self::INVALID;
        }

        $root = rtrim(str_replace('\\', '/', (string) realpath(base_path())), '/');
        $folder = realpath(base_path((string) $this->option('path')));
        $folder = $folder === false ? false : str_replace('\\', '/', $folder);
        if ($folder === false || ! is_dir($folder) || ! str_starts_with($folder.'/', $root.'/')) {
            $this->error('The path must be an existing folder inside the repository.');

            return self::INVALID;
        }

        $actor = $this->actor();
        if ($actor === null) {
            $this->error('No user to record as author: pass --user=<id> or create a user first.');

            return self::INVALID;
        }

        $created = $skipped = $revised = 0;
        foreach ($this->markdownFiles($folder) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen($root)), '/');
            $body = (string) file_get_contents($path);
            $checksum = KnowledgeDocumentService::checksum($body);
            $slug = mb_substr(Str::slug(preg_replace('/\.md$/iu', '', $relative) ?? $relative), 0, 180);

            $existing = IntelligenceKnowledgeDocument::query()->where('slug', $slug)->first();
            if ($existing !== null && $existing->checksum === $checksum) {
                $skipped++;

                continue;
            }

            if ($existing === null) {
                $document = $documents->create($actor, [
                    'title' => self::title($body, $relative), 'slug' => $slug, 'body' => $body, 'source_type' => 'repo_doc', 'source_path' => $relative,
                    'classification' => $classification, 'language' => 'en',
                ]);
                $created++;
            } else {
                $document = $documents->update($actor, $existing, ['title' => self::title($body, $relative), 'body' => $body, 'source_path' => $relative]);
                $revised++;
            }
            if ($this->option('approve')) {
                $documents->approve($actor, $document);
            }
            unset($body);
        }

        $this->info(sprintf('Imported %d new, revised %d, skipped %d unchanged%s.', $created, $revised, $skipped, $this->option('approve') ? ' (approved)' : ''));

        return self::SUCCESS;
    }

    /** @return iterable<SplFileInfo> the *.md files under the folder, one at a time, without following symlinks out of it */
    private function markdownFiles(string $folder): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));
        $root = rtrim(str_replace('\\', '/', (string) realpath(base_path())), '/');
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $real = str_replace('\\', '/', (string) $file->getRealPath());
            if (! str_starts_with($real, $root.'/')) {
                continue;
            }
            yield $file;
        }
    }

    private function actor(): ?User
    {
        $id = $this->option('user');
        if ($id !== null && $id !== '') {
            return User::query()->find((int) $id);
        }

        return User::query()->orderBy('id')->first();
    }

    /** The first `# ` heading, or the file name. */
    private static function title(string $body, string $relative): string
    {
        if (preg_match('/^#\s+(.+?)\s*#*\s*$/mu', $body, $m)) {
            return mb_substr(trim($m[1]), 0, 190);
        }

        return mb_substr(basename($relative, '.md'), 0, 190);
    }
}
