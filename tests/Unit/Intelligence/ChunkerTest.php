<?php

declare(strict_types=1);

use App\Services\Intelligence\Knowledge\Chunker;

/*
 * The chunker (docs/intelligence-knowledge.md): headings open sections,
 * long sections become overlapping windows cut on paragraph boundaries,
 * front matter is dropped, and the same body always chunks the same way.
 */

test('a body splits by heading, keeps positions sequential and drops front matter', function (): void {
    $body = "---\ntitle: Leave policy\n---\nIntro paragraph before any heading.\n\n# Annual leave\n\nEvery employee earns annual leave.\n\n## Carry-over\n\nUp to ten days carry over.\n\n### Exceptions\n\nNone.\n\n#### Not a section heading\n\nStill in exceptions.";

    $chunks = Chunker::chunk($body);

    expect(array_column($chunks, 'heading'))->toBe([null, 'Annual leave', 'Carry-over', 'Exceptions'])
        ->and(array_column($chunks, 'position'))->toBe([0, 1, 2, 3])
        ->and($chunks[0]['content'])->toBe('Intro paragraph before any heading.')
        ->and($chunks[0]['content'])->not->toContain('title: Leave policy')
        ->and($chunks[3]['content'])->toContain('#### Not a section heading')
        ->and($chunks[3]['content'])->toContain('Still in exceptions.');
});

test('a long section becomes windows of about 1,200 characters, cut on paragraphs, overlapping by about 150', function (): void {
    $paragraphs = [];
    for ($i = 1; $i <= 12; $i++) {
        $paragraphs[] = str_repeat("Paragraph {$i} sentence about the procedure. ", 8);
    }
    $body = "# Procedure\n\n".implode("\n\n", $paragraphs);

    $chunks = Chunker::chunk($body);

    expect(count($chunks))->toBeGreaterThan(2);
    foreach ($chunks as $chunk) {
        expect($chunk['heading'])->toBe('Procedure')
            ->and(mb_strlen($chunk['content']))->toBeLessThanOrEqual(Chunker::WINDOW + Chunker::OVERLAP);
    }
    // Each window starts with the tail of the previous one: the overlap keeps a cut sentence findable.
    for ($i = 1; $i < count($chunks); $i++) {
        $tail = mb_substr($chunks[$i - 1]['content'], -40);
        expect(mb_substr($chunks[$i]['content'], 0, Chunker::OVERLAP + 10))->toContain(trim($tail));
    }
    // Every paragraph appears in at least one window; nothing is lost at a cut.
    foreach ($paragraphs as $i => $paragraph) {
        expect(implode("\n", array_column($chunks, 'content')))->toContain('Paragraph '.($i + 1).' sentence');
    }
    expect(Chunker::chunk($body))->toBe($chunks);
});

test('a single paragraph longer than a window is split on whitespace and a fenced heading is not a section', function (): void {
    $long = str_repeat('word ', 600);
    $chunks = Chunker::chunk("# One\n\n{$long}\n\n```\n# not a heading\n```\n\nAfter the fence.");

    expect(count($chunks))->toBeGreaterThanOrEqual(3)
        ->and(array_unique(array_column($chunks, 'heading')))->toBe(['One'])
        ->and(end($chunks)['content'])->toContain('After the fence.');
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk['content']))->toBeLessThanOrEqual(Chunker::WINDOW + Chunker::OVERLAP);
    }
});

test('Ethiopic text survives chunking byte for byte', function (): void {
    // ቅ is E1 89 85; a bytewise \R would split on the 0x85 byte and leave an invalid sequence.
    $body = "# የፈቃድ ፖሊሲ\n\nእያንዳንዱ ሠራተኛ ዓመታዊ ፈቃድ ያገኛል። ቅድሚያ የሚሰጠው ለቅርብ ጊዜ ጥያቄ ነው።\n\n## ቅናሽ\n\nእስከ አሥር ቀናት ይተላለፋል።";

    $chunks = Chunker::chunk($body);

    expect(array_column($chunks, 'heading'))->toBe(['የፈቃድ ፖሊሲ', 'ቅናሽ'])
        ->and($chunks[0]['content'])->toContain('ቅድሚያ የሚሰጠው ለቅርብ ጊዜ ጥያቄ ነው።');
    foreach ($chunks as $chunk) {
        expect(mb_check_encoding($chunk['content'], 'UTF-8'))->toBeTrue()
            ->and(mb_check_encoding((string) $chunk['heading'], 'UTF-8'))->toBeTrue();
    }
});

test('an empty or heading-only body yields no chunks', function (): void {
    expect(Chunker::chunk(''))->toBe([])
        ->and(Chunker::chunk("# Title only\n\n## Another"))->toBe([]);
});
