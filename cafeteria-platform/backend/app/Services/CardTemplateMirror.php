<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CardTemplate;
use App\Models\CardTemplateVersion;
use App\Models\IdCardTemplate;
use App\Models\User;
use App\Support\CardTemplateConfig;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the print record in step with the designer. The screens design cards
 * on the EUISIS template page (id_card_templates); printing and batches
 * record the Core's template version (ADR 0010). When the default active
 * EUISIS template changes, its shape becomes the active version, so a batch
 * can always be printed without a second place to activate anything.
 */
final class CardTemplateMirror
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function sync(IdCardTemplate $template): void
    {
        if (! $template->is_default || $template->status !== 'active') {
            return;
        }
        $config = $this->config($template);
        $actor = $template->updated_by === null ? null : User::query()->find($template->updated_by);

        DB::transaction(function () use ($template, $config, $actor): void {
            $current = CardTemplateVersion::query()->where('status', CardTemplateVersion::ACTIVE)->lockForUpdate()->first();
            $record = CardTemplate::query()->firstOrCreate(['code' => $template->code], [
                'name_en' => $template->name, 'description' => $template->description, 'created_by' => $actor?->getKey(),
            ]);
            if ($current !== null && (string) $current->card_template_id === (string) $record->id && $current->config == $config) {
                return;
            }
            $current?->update(['status' => CardTemplateVersion::RETIRED, 'retired_by' => $actor?->getKey(), 'retired_at' => now()]);
            $version = $record->versions()->create([
                'version' => (int) $record->versions()->max('version') + 1,
                'status' => CardTemplateVersion::ACTIVE,
                'config' => $config,
                'created_by' => $actor?->getKey(),
                'activated_by' => $actor?->getKey(),
                'activated_at' => now(),
            ]);
            $this->audit->writeFor('card_template.mirrored', $actor, 'CardTemplateVersion', (string) $version->id, null,
                ['active_version_id' => $current?->id], ['active_version_id' => $version->id, 'id_card_template_id' => $template->id]);
        });
    }

    /**
     * The print record's shape for a designed template: its orientation and
     * header; the printed fields and colours stay the print renderer's defaults.
     *
     * @return array<string, mixed>
     */
    private function config(IdCardTemplate $template): array
    {
        $header = (array) ($template->header_config ?? []);

        return CardTemplateConfig::validate([
            ...CardTemplateConfig::defaults(),
            'orientation' => $template->orientation === 'portrait' ? 'portrait' : 'landscape',
            'header_en' => trim((string) ($header['city_name_en'] ?? '')),
            'header_am' => trim((string) ($header['city_name_am'] ?? '')),
        ], false);
    }
}
