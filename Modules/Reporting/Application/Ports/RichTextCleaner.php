<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

/** Sanitizes narrative HTML to the application's rich-text policy before it is stored. */
interface RichTextCleaner
{
    public function clean(?string $html): string;
}
