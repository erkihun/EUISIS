<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Knowledge;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IntelligenceKnowledgeChunk;
use App\Models\IntelligenceKnowledgeDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lifecycle of a knowledge document (docs/intelligence-knowledge.md):
 * draft → approved → retired, with the chunks rebuilt whenever the body
 * changes and every transition audited. The audit row carries the title,
 * status, classification and version — never the body.
 */
final class KnowledgeDocumentService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data  title, body, classification, language, organization_id, required_permission, source_type, source_path, slug */
    public function create(User $actor, array $data): IntelligenceKnowledgeDocument
    {
        $body = (string) ($data['body'] ?? '');

        return DB::transaction(function () use ($actor, $data, $body): IntelligenceKnowledgeDocument {
            $document = IntelligenceKnowledgeDocument::query()->create([
                'title' => (string) $data['title'],
                'slug' => $this->uniqueSlug((string) ($data['slug'] ?? $data['title'])),
                'source_type' => (string) ($data['source_type'] ?? 'manual'),
                'source_path' => $data['source_path'] ?? null,
                'classification' => (string) ($data['classification'] ?? 'internal'),
                'organization_id' => $data['organization_id'] ?? null,
                'required_permission' => $data['required_permission'] ?? null,
                'language' => (string) ($data['language'] ?? 'en'),
                'status' => 'draft',
                'version' => 1,
                'checksum' => self::checksum($body),
                'body' => $body,
                'created_by' => $actor->id,
            ]);
            $this->rechunk($document);
            $this->record($actor, $document, 'created');

            return $document;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, IntelligenceKnowledgeDocument $document, array $data): IntelligenceKnowledgeDocument
    {
        return DB::transaction(function () use ($actor, $document, $data): IntelligenceKnowledgeDocument {
            $before = $this->snapshot($document);
            foreach (['title', 'classification', 'language', 'organization_id', 'required_permission', 'source_path'] as $field) {
                if (array_key_exists($field, $data)) {
                    $document->{$field} = $data[$field];
                }
            }
            $bodyChanged = false;
            if (array_key_exists('body', $data) && self::checksum((string) $data['body']) !== $document->checksum) {
                // New text is a new version that has not been approved yet: the approved words were the old ones.
                $document->body = (string) $data['body'];
                $document->checksum = self::checksum($document->body);
                $document->version = $document->version + 1;
                $document->status = 'draft';
                $document->approved_by = null;
                $document->approved_at = null;
                $bodyChanged = true;
            }
            $document->save();
            if ($bodyChanged) {
                $this->rechunk($document);
            }
            $this->record($actor, $document, $bodyChanged ? 'revised' : 'updated', $before);

            return $document;
        });
    }

    public function approve(User $actor, IntelligenceKnowledgeDocument $document): IntelligenceKnowledgeDocument
    {
        $before = $this->snapshot($document);
        $document->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
        $this->record($actor, $document, 'approved', $before);

        return $document;
    }

    public function retire(User $actor, IntelligenceKnowledgeDocument $document): IntelligenceKnowledgeDocument
    {
        $before = $this->snapshot($document);
        $document->forceFill(['status' => 'retired'])->save();
        $this->record($actor, $document, 'retired', $before);

        return $document;
    }

    public function delete(User $actor, IntelligenceKnowledgeDocument $document): void
    {
        $before = $this->snapshot($document);
        // The audit row is written first so the auditable id still names a row that existed.
        $this->record($actor, $document, 'deleted', $before);
        $document->delete();
    }

    /** Rebuild the chunks from the body: bounded by the body length, written in one insert batch per 200 chunks. */
    public function rechunk(IntelligenceKnowledgeDocument $document): int
    {
        IntelligenceKnowledgeChunk::query()->where('document_id', $document->id)->delete();
        $now = now();
        $rows = [];
        foreach (Chunker::chunk($document->body) as $chunk) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'document_id' => $document->id,
                'position' => $chunk['position'],
                'heading' => $chunk['heading'],
                'content' => $chunk['content'],
                'char_count' => mb_strlen($chunk['content']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $batch) {
            IntelligenceKnowledgeChunk::query()->insert($batch);
        }

        return count($rows);
    }

    public static function checksum(string $body): string
    {
        return sha1(str_replace("\r\n", "\n", $body));
    }

    private function uniqueSlug(string $source): string
    {
        $base = mb_substr(Str::slug($source) ?: 'document', 0, 180);
        $slug = $base;
        for ($i = 2; IntelligenceKnowledgeDocument::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** @return array<string, mixed> what an audit row may carry: never the body */
    private function snapshot(IntelligenceKnowledgeDocument $document): array
    {
        return $document->only(['title', 'status', 'classification', 'language', 'organization_id', 'required_permission', 'version', 'checksum']);
    }

    /** @param  array<string, mixed>|null  $before */
    private function record(User $actor, IntelligenceKnowledgeDocument $document, string $action, ?array $before = null): void
    {
        $this->audit->execute(
            AuditEventType::IntelligenceKnowledgeDocumentChanged,
            $actor,
            $document,
            organizationId: $document->organization_id,
            oldValues: $before,
            newValues: ['action' => $action, ...$this->snapshot($document)],
        );
    }
}
