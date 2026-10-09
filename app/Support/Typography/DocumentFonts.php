<?php

declare(strict_types=1);

namespace App\Support\Typography;

use InvalidArgumentException;

/**
 * The fonts PDF templates embed, taken from config/typography.php.
 *
 * Templates include `pdf.partials.typography`, which prints these rules, so
 * no template names a font file or a family itself. Every path is resolved
 * inside the configured font directory; a variant or file outside the config
 * is refused rather than read.
 */
final class DocumentFonts
{
    /** Faces declared per family. Missing weights/styles map to real files. */
    private const FACES = [
        ['weight' => 'normal', 'style' => 'normal', 'file' => 'normal'],
        ['weight' => 'bold', 'style' => 'normal', 'file' => 'bold'],
        ['weight' => 'normal', 'style' => 'italic', 'file' => 'normal'],
        ['weight' => 'bold', 'style' => 'italic', 'file' => 'bold'],
    ];

    /** @return list<string> */
    public function variants(): array
    {
        return array_keys((array) config('typography.documents', []));
    }

    /** `@font-face` rules for a variant, plus the page's base font-family. */
    public function css(string $variant = 'report'): string
    {
        $definition = $this->definition($variant);
        $family = $this->quote($definition['family']);
        $rules = [];

        foreach (self::FACES as $face) {
            $rules[] = sprintf(
                '@font-face { font-family: %s; font-weight: %s; font-style: %s; src: url("%s") format("truetype"); }',
                $family,
                $face['weight'],
                $face['style'],
                $this->path($definition['files'][$face['file']] ?? $definition['files']['normal']),
            );
        }

        $rules[] = sprintf('html, body { font-family: %s; }', $this->family($variant));

        return implode("\n", $rules);
    }

    /** The CSS font-family value for a variant: the embedded family, then fallbacks. */
    public function family(string $variant = 'report'): string
    {
        $definition = $this->definition($variant);

        return implode(', ', array_map(
            fn (string $name): string => in_array($name, ['serif', 'sans-serif', 'monospace'], true) ? $name : $this->quote($name),
            [$definition['family'], ...($definition['fallback'] ?? [])],
        ));
    }

    /**
     * Absolute path of a bundled font file, with forward slashes (a backslash
     * inside a CSS string is an escape character).
     */
    public function path(string $file): string
    {
        $directory = realpath((string) config('typography.font_path'));
        $path = $directory === false ? false : realpath($directory.DIRECTORY_SEPARATOR.$file);

        if ($directory === false || $path === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Font file [{$file}] is not a bundled font.");
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * A variant's fonts for the current app locale: its `locales` entry for
     * that locale when there is one, else the variant itself.
     *
     * @return array{family: string, files: array<string, string>, fallback?: list<string>}
     */
    private function definition(string $variant): array
    {
        $definition = config("typography.documents.{$variant}");

        if (! is_array($definition) || ! isset($definition['family'], $definition['files']['normal'])) {
            throw new InvalidArgumentException("Unknown document typography variant [{$variant}].");
        }

        $localized = $definition['locales'][app()->getLocale()] ?? null;

        return is_array($localized) && isset($localized['family'], $localized['files']['normal']) ? $localized : $definition;
    }

    private function quote(string $name): string
    {
        return "'".str_replace(["'", '\\', '<', '>'], '', $name)."'";
    }
}
