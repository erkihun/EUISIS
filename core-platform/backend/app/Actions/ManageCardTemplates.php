<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CardTemplate;
use App\Models\CardTemplateVersion;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Support\CardTemplateConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Card templates (ADR 0010), city-wide, by card_templates.manage holders.
 * A version is edited only while a draft; activating it retires the version
 * active before (one at a time) and needs someone other than its author; a
 * retired version is kept because printed cards name it. Every step is audited.
 */
final readonly class ManageCardTemplates
{
    public function __construct(private AuditWriter $audit, private CoreSettings $settings) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, User $actor): CardTemplate
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('card_templates', 'code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($data, $actor): CardTemplate {
            $template = CardTemplate::query()->create($data + ['created_by' => $actor->getKey()]);
            $version = $template->versions()->create(['version' => 1, 'status' => CardTemplateVersion::DRAFT, 'config' => CardTemplateConfig::defaults(), 'created_by' => $actor->getKey()]);
            $this->audit->writeFor('card_template.created', $actor, 'CardTemplate', (string) $template->id, null, null, ['code' => $template->code, 'version_id' => $version->id]);

            return $template;
        });
    }

    /** A new draft from the template's latest layout; one draft per template at a time. */
    public function newVersion(CardTemplate $template, User $actor): CardTemplateVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($template, $actor): CardTemplateVersion {
            $template = CardTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->versions()->where('status', CardTemplateVersion::DRAFT)->exists()) {
                throw ValidationException::withMessages(['version' => 'This template already has a draft; edit it instead.']);
            }
            $latest = $template->versions()->first();
            $version = $template->versions()->create([
                'version' => (int) ($latest?->version ?? 0) + 1, 'status' => CardTemplateVersion::DRAFT,
                'config' => $latest?->config ?? CardTemplateConfig::defaults(), 'created_by' => $actor->getKey(),
            ]);
            $this->audit->writeFor('card_template.version_created', $actor, 'CardTemplateVersion', (string) $version->id, null, null, ['version' => $version->version]);

            return $version;
        });
    }

    /** @param array<string, mixed> $config */
    public function saveDraft(CardTemplateVersion $version, array $config, User $actor): CardTemplateVersion
    {
        $this->authorize($actor);
        $config = CardTemplateConfig::validate($config, (bool) $this->settings->get('id_cards.require_photo'));

        return DB::transaction(function () use ($version, $config, $actor): CardTemplateVersion {
            $version = CardTemplateVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status !== CardTemplateVersion::DRAFT) {
                throw ValidationException::withMessages(['version' => 'Only a draft can be edited; make a new version.']);
            }
            $old = $version->config;
            $version->update(['config' => $config]);
            $this->audit->writeFor('card_template.draft_saved', $actor, 'CardTemplateVersion', (string) $version->id, null, $old, $config);

            return $version;
        });
    }

    public function activate(CardTemplateVersion $version, User $actor): CardTemplateVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($version, $actor): CardTemplateVersion {
            // Serialize activations: every one locks the version that is active now.
            $current = CardTemplateVersion::query()->where('status', CardTemplateVersion::ACTIVE)->lockForUpdate()->first();
            $version = CardTemplateVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status !== CardTemplateVersion::DRAFT) {
                throw ValidationException::withMessages(['version' => 'Only a draft can be activated.']);
            }
            if ($version->created_by !== null && (int) $version->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['version' => 'Someone other than the person who designed this version activates it.']);
            }
            CardTemplateConfig::validate((array) $version->config, (bool) $this->settings->get('id_cards.require_photo'));
            $current?->update(['status' => CardTemplateVersion::RETIRED, 'retired_by' => $actor->getKey(), 'retired_at' => now()]);
            $version->update(['status' => CardTemplateVersion::ACTIVE, 'activated_by' => $actor->getKey(), 'activated_at' => now()]);
            $this->audit->writeFor('card_template.activated', $actor, 'CardTemplateVersion', (string) $version->id, null,
                ['active_version_id' => $current?->id], ['active_version_id' => $version->id]);

            return $version;
        });
    }

    /** Retires a draft, or the active version (printing then waits for another to be activated). */
    public function retire(CardTemplateVersion $version, string $reason, User $actor): CardTemplateVersion
    {
        $this->authorize($actor);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason (at least 5 characters).']);
        }

        return DB::transaction(function () use ($version, $reason, $actor): CardTemplateVersion {
            $version = CardTemplateVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status === CardTemplateVersion::RETIRED) {
                throw ValidationException::withMessages(['version' => 'This version is already retired.']);
            }
            $version->update(['status' => CardTemplateVersion::RETIRED, 'retired_by' => $actor->getKey(), 'retired_at' => now()]);
            $this->audit->writeFor('card_template.retired', $actor, 'CardTemplateVersion', (string) $version->id, null, null, ['status' => 'retired'], $reason);

            return $version;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('card_templates.manage'), 403);
    }
}
