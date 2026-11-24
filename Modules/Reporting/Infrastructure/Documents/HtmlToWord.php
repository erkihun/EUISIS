<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Style\ListItem;

/**
 * Writes sanitized narrative HTML into a Word container as real Word
 * structures: paragraphs, headings, bulleted and numbered lists, tables and
 * basic emphasis, so the result stays editable in Word.
 *
 * Deliberately small: it understands the tags the rich-text policy allows and
 * drops everything else. Images, frames and links are never fetched; a link
 * is written as its text followed by its address.
 */
final class HtmlToWord
{
    private const BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'pre'];

    /** Usable text width of an A4 page with the renderer's margins, in twips. */
    private const TEXT_WIDTH = 9300;

    public function write(AbstractContainer $container, string $html, int $width = self::TEXT_WIDTH): void
    {
        if (trim($html) === '') {
            return;
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        // No network, no entity substitution; parse errors of loose HTML are tolerated.
        @$document->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        $root = $document->getElementById('root');
        if ($root !== null) {
            $this->blocks($container, $root, $width);
        }
    }

    private function blocks(AbstractContainer $container, DOMNode $parent, int $width): void
    {
        /** @var list<DOMNode> $inline nodes between two blocks form one paragraph */
        $inline = [];
        $flush = function () use (&$inline, $container): void {
            if (trim(implode('', array_map(fn (DOMNode $n): string => $n->textContent, $inline))) !== '') {
                $run = $container->addTextRun(['spaceAfter' => 120]);
                foreach ($inline as $node) {
                    $this->inline($run, $node, []);
                }
            }
            $inline = [];
        };

        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement || ! in_array(strtolower($node->tagName), self::BLOCKS, true)) {
                $inline[] = $node;

                continue;
            }
            $flush();
            $tag = strtolower($node->tagName);
            match (true) {
                $tag === 'ul' || $tag === 'ol' => $this->list($container, $node, 0),
                $tag === 'table' => $this->table($container, $node, $width),
                $tag[0] === 'h' && strlen($tag) === 2 => $this->paragraph($container, $node, ['bold' => true, 'size' => $tag <= 'h2' ? 13 : 12], ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true]),
                $tag === 'blockquote' => $this->paragraph($container, $node, ['italic' => true], ['indentation' => ['left' => 480], 'spaceAfter' => 120]),
                // A div holding further blocks is unwrapped; one holding text is a paragraph.
                $tag === 'div' && $this->hasBlocks($node) => $this->blocks($container, $node, $width),
                default => $this->paragraph($container, $node, [], ['spaceAfter' => 120, ...$this->alignment($node)]),
            };
        }
        $flush();
    }

    /**
     * @param  array<string, mixed>  $font
     * @param  array<string, mixed>  $paragraph
     */
    private function paragraph(AbstractContainer $container, DOMElement $element, array $font, array $paragraph): void
    {
        if (trim($element->textContent) === '') {
            return;
        }
        $run = $container->addTextRun($paragraph);
        foreach (iterator_to_array($element->childNodes) as $child) {
            $this->inline($run, $child, $font);
        }
    }

    /** @param array<string, mixed> $font the emphasis inherited from enclosing tags */
    private function inline(AbstractContainer $run, DOMNode $node, array $font): void
    {
        if ($node instanceof DOMText) {
            $text = (string) preg_replace('/\s+/u', ' ', $node->nodeValue ?? '');
            if ($text !== '') {
                $run->addText($text, $font);
            }

            return;
        }
        if (! $node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            $run->addTextBreak();

            return;
        }
        if (in_array($tag, ['img', 'script', 'style', 'iframe', 'object', 'embed', 'svg'], true)) {
            return;
        }
        $font = match ($tag) {
            'strong', 'b', 'th' => [...$font, 'bold' => true],
            'em', 'i' => [...$font, 'italic' => true],
            'u' => [...$font, 'underline' => 'single'],
            's', 'strike', 'del' => [...$font, 'strikethrough' => true],
            'sup' => [...$font, 'superScript' => true],
            'sub' => [...$font, 'subScript' => true],
            default => $font,
        };
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->inline($run, $child, $font);
        }
        if ($tag === 'a' && ($href = trim($node->getAttribute('href'))) !== '' && preg_match('#^(https?:|mailto:)#i', $href) === 1 && $href !== trim($node->textContent)) {
            $run->addText(" ({$href})", $font);
        }
    }

    private function list(AbstractContainer $container, DOMElement $list, int $depth): void
    {
        $style = ['listType' => strtolower($list->tagName) === 'ol' ? ListItem::TYPE_NUMBER_NESTED : ListItem::TYPE_BULLET_FILLED];
        foreach (iterator_to_array($list->childNodes) as $item) {
            if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }
            $nested = [];
            $run = $container->addListItemRun(min($depth, 8), $style, ['spaceAfter' => 60]);
            foreach (iterator_to_array($item->childNodes) as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $nested[] = $child;
                } else {
                    $this->inline($run, $child, []);
                }
            }
            foreach ($nested as $child) {
                $this->list($container, $child, $depth + 1);
            }
        }
    }

    private function table(AbstractContainer $container, DOMElement $table, int $width): void
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $row) {
            // Rows of a table nested in a cell belong to that inner table.
            if ($this->closest($row, 'table') !== $table) {
                continue;
            }
            $cells = array_values(array_filter(iterator_to_array($row->childNodes), fn (DOMNode $n): bool => $n instanceof DOMElement && in_array(strtolower($n->tagName), ['td', 'th'], true)));
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return;
        }
        $columns = max(array_map(fn (array $cells): int => array_sum(array_map(fn (DOMElement $c): int => max(1, (int) $c->getAttribute('colspan')), $cells)), $rows));
        $unit = intdiv($width, max(1, min($columns, 12)));

        $wordTable = $container->addTable(['borderSize' => 6, 'borderColor' => '808080', 'cellMargin' => 80]);
        foreach ($rows as $cells) {
            $wordTable->addRow();
            foreach ($cells as $cell) {
                $span = max(1, (int) $cell->getAttribute('colspan'));
                $header = strtolower($cell->tagName) === 'th';
                $wordCell = $wordTable->addCell($unit * $span, array_filter(['gridSpan' => $span > 1 ? $span : null, 'bgColor' => $header ? 'EDEDED' : null]));
                if ($this->hasBlocks($cell)) {
                    $this->blocks($wordCell, $cell, $unit * $span - 160);
                } else {
                    $run = $wordCell->addTextRun(['spaceAfter' => 0]);
                    foreach (iterator_to_array($cell->childNodes) as $child) {
                        $this->inline($run, $child, $header ? ['bold' => true] : []);
                    }
                }
            }
        }
        $container->addTextBreak();
    }

    private function hasBlocks(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), self::BLOCKS, true)) {
                return true;
            }
        }

        return false;
    }

    private function closest(DOMNode $node, string $tag): ?DOMNode
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === $tag) {
                return $parent;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function alignment(DOMElement $element): array
    {
        return preg_match('/text-align:\s*(left|center|right|justify)/i', $element->getAttribute('style'), $m) === 1
            ? ['alignment' => ['left' => 'start', 'center' => 'center', 'right' => 'end', 'justify' => 'both'][strtolower($m[1])]]
            : [];
    }
}
