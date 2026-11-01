<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use HTMLPurifier;
use HTMLPurifier_AttrDef;
use HTMLPurifier_Config;
use HTMLPurifier_HTMLDefinition;
use Illuminate\Support\Facades\File;

/**
 * HTML cleaning for the court's documents with the profiles court-ms used
 * (config `court.purifier`), on HTML Purifier directly instead of mews/purifier.
 * The setup follows that package: a profile's settings over the defaults, then
 * the custom definition, elements and attributes (the HTML5 tags) on every profile.
 */
final class Purifier
{
    /** @var array<string, HTMLPurifier> */
    private static array $purifiers = [];

    public static function clean(?string $html, string|array|null $profile = 'default'): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return self::purifier(is_string($profile) ? $profile : 'default')->purify($html);
    }

    private static function purifier(string $profile): HTMLPurifier
    {
        if (isset(self::$purifiers[$profile])) {
            return self::$purifiers[$profile];
        }

        $cache = storage_path('framework/cache/court-purifier');
        File::ensureDirectoryExists($cache);
        $settings = config('court.purifier.settings.'.$profile);
        $settings = is_array($settings) ? $settings : (array) config('court.purifier.settings.default', []);

        $config = HTMLPurifier_Config::createDefault();
        if (! config('court.purifier.finalize', true)) {
            $config->autoFinalize = false;
        }
        $config->loadArray([
            'Core.Encoding' => (string) config('court.purifier.encoding', 'UTF-8'),
            'Cache.SerializerPath' => $cache,
            'Cache.SerializerPermissions' => 0755,
        ] + $settings);

        if (is_array($definition = config('court.purifier.settings.custom_definition'))) {
            $config->set('HTML.DefinitionID', $definition['id']);
            $config->set('HTML.DefinitionRev', $definition['rev']);
            if (! isset($definition['debug']) || $definition['debug']) {
                $config->set('Cache.DefinitionImpl', null);
            }
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                self::attributes((array) ($definition['attributes'] ?? []), $def);
                self::elements((array) ($definition['elements'] ?? []), $def);
            }
        }
        if (is_array($elements = config('court.purifier.settings.custom_elements')) && ($def = $config->maybeGetRawHTMLDefinition())) {
            self::elements($elements, $def);
        }
        if (is_array($attributes = config('court.purifier.settings.custom_attributes')) && ($def = $config->maybeGetRawHTMLDefinition())) {
            self::attributes($attributes, $def);
        }

        return self::$purifiers[$profile] = new HTMLPurifier($config);
    }

    private static function elements(array $elements, HTMLPurifier_HTMLDefinition $definition): void
    {
        foreach ($elements as $element) {
            empty($element[4])
                ? $definition->addElement($element[0], $element[1], $element[2], $element[3])
                : $definition->addElement($element[0], $element[1], $element[2], $element[3], $element[4]);
        }
    }

    private static function attributes(array $attributes, HTMLPurifier_HTMLDefinition $definition): void
    {
        foreach ($attributes as $attribute) {
            $name = ! empty($attribute[3]) ? $attribute[1].'*' : $attribute[1];
            $valid = $attribute[2];
            if ($attribute[0] === '*') {
                $def = is_string($valid) ? new $valid : $valid;
                if ($def instanceof HTMLPurifier_AttrDef) {
                    $definition->info_global_attr[$name] = $def;
                }

                continue;
            }
            if (is_string($valid) && class_exists($valid)) {
                $valid = new $valid;
            }
            $definition->addAttribute($attribute[0], $name, $valid);
        }
    }
}
