<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Models\IdCardTemplate;
use App\Models\User;
use App\Services\IdCards\IdCardTemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class SaveIdCardTemplateAction
{
    public function __construct(private IdCardTemplateService $templates) {}

    public function execute(array $data, User $actor, ?IdCardTemplate $template = null): IdCardTemplate
    {
        // Serialize default selection and replacement, including the first template.
        return Cache::lock('id-card-templates:write', 60)->block(10, function () use ($data, $actor, $template): IdCardTemplate {
            $newPaths = [];
            $oldPaths = [];
            try {
                $saved = DB::transaction(function () use ($data, $actor, $template, &$newPaths, &$oldPaths): IdCardTemplate {
                    $record = $template ? IdCardTemplate::query()->lockForUpdate()->findOrFail($template->id) : new IdCardTemplate;
                    if ((bool) $data['is_default'] !== (bool) $record->is_default) {
                        abort_unless($actor->can('id_card_templates.set_default'), 403);
                    }
                    // A back-only edit must not erase the saved front or other roles.
                    if (is_array($data['text_style_config'] ?? null)) {
                        $data['text_style_config'] = array_replace_recursive(
                            $record->text_style_config ?? [], $data['text_style_config'],
                        );
                    } else {
                        unset($data['text_style_config']);
                    }
                    // Likewise for the header: a partial edit keeps the fields
                    // this request did not send.
                    if (is_array($data['header_config'] ?? null)) {
                        $data['header_config'] = array_replace(
                            $record->header_config ?? [], $data['header_config'],
                        );
                    } else {
                        unset($data['header_config']);
                    }
                    $record->fill(Arr::only($data, [
                        'name', 'code', 'description', 'orientation', 'width_mm', 'height_mm', 'status', 'is_default',
                        'text_style_config', 'layout_config', 'header_config', 'back_photo_config',
                    ]));
                    // Both header logos are stored like the backgrounds, each
                    // input name mapping to its own column.
                    foreach ([
                        'front_background' => 'front_background_path',
                        'back_background' => 'back_background_path',
                        'logo_primary' => 'logo_primary_path',
                        'logo_secondary' => 'logo_secondary_path',
                    ] as $input => $column) {
                        $file = $data[$input] ?? null;
                        if ($file instanceof UploadedFile) {
                            $path = 'id-card-templates/'.Str::uuid7().'.png';
                            $newPaths[] = $path;
                            if (! Storage::disk('local')->putFileAs('id-card-templates', $file, basename($path))) {
                                throw ValidationException::withMessages([$input => __('id-card-templates.upload_failed')]);
                            }
                            $oldPaths[] = $record->{$column};
                            $record->{$column} = $path;
                        } elseif ($data['remove_'.$input] ?? false) {
                            $oldPaths[] = $record->{$column};
                            $record->{$column} = null;
                        }
                    }
                    if ($record->is_default) {
                        IdCardTemplate::query()->where('is_default', true)
                            ->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))
                            ->update(['is_default' => false, 'updated_by' => $actor->id]);
                    }
                    if (! $record->exists) {
                        $record->created_by = $actor->id;
                    }
                    $record->updated_by = $actor->id;
                    $record->save();

                    return $record;
                });
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($newPaths);
                throw $exception;
            }
            // Only discard previous files after every upload and the database commit succeed.
            foreach ($oldPaths as $path) {
                if ($this->templates->safePath($path)) {
                    Storage::disk('local')->delete($path);
                }
            }

            return $saved;
        });
    }

    public function delete(IdCardTemplate $template, User $actor): void
    {
        Cache::lock('id-card-templates:write', 60)->block(10, function () use ($template, $actor): void {
            DB::transaction(function () use ($template, $actor): void {
                $record = IdCardTemplate::query()->lockForUpdate()->findOrFail($template->id);
                abort_if($record->is_default && ! $actor->can('id_card_templates.set_default'), 403);
                $record->update(['is_default' => false, 'updated_by' => $actor->id]);
                $record->delete();
                // Retain assets with the soft-deleted record for recovery.
            });
        });
    }

    public function setDefault(IdCardTemplate $template, User $actor): void
    {
        Gate::forUser($actor)->authorize('id_card_templates.set_default');
        Cache::lock('id-card-templates:write', 60)->block(10, function () use ($template, $actor): void {
            DB::transaction(function () use ($template, $actor): void {
                $record = IdCardTemplate::query()->lockForUpdate()->findOrFail($template->id);
                if ($record->status !== 'active') {
                    throw ValidationException::withMessages(['status' => __('id-card-templates.default_active')]);
                }
                IdCardTemplate::query()->where('is_default', true)->whereKeyNot($record->id)
                    ->update(['is_default' => false, 'updated_by' => $actor->id]);
                $record->update(['is_default' => true, 'updated_by' => $actor->id]);
            });
        });
    }
}
