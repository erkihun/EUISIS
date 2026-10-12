<?php

use Illuminate\Support\Facades\App;

/*
 * Status and type codes are never shown raw (docs/amharic-language-audit.md).
 * Every enum that labels itself must give each case Amharic text in Amharic
 * and English text in English. Enums labelled in the React app instead are
 * covered by the translation audit's key parity between the two locales.
 */
function selfLabelledEnums(): array
{
    $enums = [];
    foreach (glob(app_path('Enums/*.php')) as $file) {
        $class = 'App\\Enums\\'.basename($file, '.php');
        if (enum_exists($class) && (method_exists($class, 'label') || method_exists($class, 'labelAm'))) {
            $enums[] = $class;
        }
    }

    return $enums;
}

function enumLabelIn(UnitEnum $case, string $locale): string
{
    App::setLocale($locale);

    if ($locale === 'am' && method_exists($case, 'labelAm')) {
        return $case->labelAm();
    }

    return (new ReflectionMethod($case, 'label'))->getNumberOfParameters() > 0
        ? $case->label($locale)
        : $case->label();
}

function enumRawCode(UnitEnum $case): string
{
    return $case instanceof BackedEnum ? (string) $case->value : $case->name;
}

it('finds the self-labelled enums', function () {
    expect(count(selfLabelledEnums()))->toBeGreaterThan(20);
});

it('labels every enum case in Amharic script, never as its raw code', function () {
    $problems = [];
    foreach (selfLabelledEnums() as $class) {
        foreach ($class::cases() as $case) {
            $label = enumLabelIn($case, 'am');
            if (! preg_match('/\p{Ethiopic}/u', $label) || $label === enumRawCode($case)) {
                $problems[] = class_basename($class)."::{$case->name} => {$label}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('labels every enum case in English without Amharic script or a translation key', function () {
    $problems = [];
    foreach (selfLabelledEnums() as $class) {
        if (! method_exists($class, 'label')) {
            continue;
        }
        foreach ($class::cases() as $case) {
            $label = enumLabelIn($case, 'en');
            if (trim($label) === '' || preg_match('/\p{Ethiopic}/u', $label) || preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $label)) {
                $problems[] = class_basename($class)."::{$case->name} => {$label}";
            }
        }
    }

    expect($problems)->toBe([]);
});
