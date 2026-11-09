<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Forms;

/**
 * What a form template changes on the official form (docs/service-monitoring.md §Form templates):
 *
 *   {
 *     title: ?string,                         replaces the form title
 *     general_notes: ?list<string>,           replaces the general notes (ማሳሰቢያ)
 *     sections: { "1".."4": { enabled: bool, title: ?string, notes: ?list<string> } },
 *     custom: { sections: [...] }             additional sections (FormSchema)
 *   }
 *
 * The four official sections keep their rules (identity, prefill,
 * verification, sums); a template switches them on or off and re-words
 * them. Everything new is a custom section. A null or missing value keeps
 * the official wording, so an empty template is the official form.
 */
final readonly class TemplateContent
{
    public const BUILT_IN = [1, 2, 3, 4];

    /** @param array<string, mixed> $content */
    private function __construct(private array $content) {}

    /** @param array<string, mixed>|null $content */
    public static function from(?array $content): self
    {
        return new self($content ?? []);
    }

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return [
            'title' => null,
            'general_notes' => null,
            'sections' => array_fill_keys(array_map('strval', self::BUILT_IN), ['enabled' => true, 'title' => null, 'notes' => null]),
            'custom' => FormSchema::empty(),
        ];
    }

    public function enabled(int $section): bool
    {
        return (bool) ($this->content['sections'][(string) $section]['enabled'] ?? true);
    }

    /** @return list<int> */
    public function enabledSections(): array
    {
        return array_values(array_filter(self::BUILT_IN, fn (int $n): bool => $this->enabled($n)));
    }

    public function custom(): FormSchema
    {
        return FormSchema::from($this->customSchema());
    }

    /** @return array<string, mixed> */
    public function customSchema(): array
    {
        $custom = $this->content['custom'] ?? null;

        return is_array($custom) && isset($custom['sections']) ? $custom : FormSchema::empty();
    }

    public function hasCustomSections(): bool
    {
        return ($this->customSchema()['sections'] ?? []) !== [];
    }

    /**
     * Problems keyed by `content.*` path. `$forPublish` adds the completeness rules.
     *
     * @return array<string, string>
     */
    public function problems(bool $forPublish = false): array
    {
        $errors = [];
        $this->checkText($this->content['title'] ?? null, 'content.title', 500, $errors);
        $this->checkNotes($this->content['general_notes'] ?? null, 'content.general_notes', $errors);

        $sections = $this->content['sections'] ?? [];
        if (! is_array($sections)) {
            $errors['content.sections'] = 'Invalid section settings.';
            $sections = [];
        }
        foreach ($sections as $key => $section) {
            $path = "content.sections.{$key}";
            if (! in_array((int) $key, self::BUILT_IN, true) || ! is_array($section)) {
                $errors[$path] = 'Unknown official section.';

                continue;
            }
            if (isset($section['enabled']) && ! is_bool($section['enabled'])) {
                $errors["{$path}.enabled"] = 'Choose whether the section is on the form.';
            }
            $this->checkText($section['title'] ?? null, "{$path}.title", 500, $errors);
            $this->checkNotes($section['notes'] ?? null, "{$path}.notes", $errors);
        }

        foreach (FormSchema::from($this->customSchema())->problems($forPublish && $this->hasCustomSections()) as $path => $message) {
            $errors['content.custom.'.substr($path, strlen('schema.'))] = $message;
        }

        if ($forPublish && $this->enabledSections() === [] && ! $this->hasCustomSections()) {
            $errors['content.sections'] = 'A form needs at least one section.';
        }

        return $errors;
    }

    /**
     * The official description with this template's wording applied, the
     * sections it leaves on, and its additional sections.
     *
     * @param  array<string, mixed>  $definition  FormDefinition::describe()
     * @return array<string, mixed>
     */
    public function overlay(array $definition): array
    {
        if (is_string($this->content['title'] ?? null) && trim($this->content['title']) !== '') {
            $definition['title'] = $this->content['title'];
        }
        if (is_array($this->content['general_notes'] ?? null)) {
            $definition['general_notes'] = array_values($this->content['general_notes']);
        }
        foreach (self::BUILT_IN as $n) {
            $section = $this->content['sections'][(string) $n] ?? [];
            if (is_string($section['title'] ?? null) && trim($section['title']) !== '') {
                $definition["section_{$n}"]['title'] = $section['title'];
            }
            if (is_array($section['notes'] ?? null)) {
                if ($n === 1) {
                    $definition['section_1']['summary']['notes'] = array_values($section['notes']);
                } else {
                    $definition["section_{$n}"]['notes'] = array_values($section['notes']);
                }
            }
        }
        $definition['enabled_sections'] = $this->enabledSections();
        $definition['custom'] = $this->customSchema();

        return $definition;
    }

    /** @param array<string, string> $errors */
    private function checkText(mixed $value, string $path, int $max, array &$errors): void
    {
        if ($value !== null && (! is_string($value) || mb_strlen($value) > $max)) {
            $errors[$path] = "At most {$max} characters.";
        }
    }

    /** @param array<string, string> $errors */
    private function checkNotes(mixed $value, string $path, array &$errors): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
            $errors[$path] = 'At most 20 notes.';

            return;
        }
        foreach ($value as $i => $note) {
            if (! is_string($note) || trim($note) === '' || mb_strlen($note) > 2000) {
                $errors["{$path}.{$i}"] = 'A note is 1–2000 characters.';
            }
        }
    }
}
