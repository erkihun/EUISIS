<?php

declare(strict_types=1);

/*
 * Lazily aliases a class name from before the move into Modules/ (ADR 0016) to
 * its current class, so payloads serialized under the old name still load.
 * Loaded through composer's "files" autoload; costs nothing until an old name
 * is actually requested.
 */
spl_autoload_register(static function (string $class): void {
    static $map = null;
    $map ??= require __DIR__.'/legacy-class-map.php';

    if (isset($map[$class])) {
        class_alias($map[$class], $class);
    }
});
