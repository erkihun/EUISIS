<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Services\LetterLayout;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Models\LetterLetterhead;

/**
 * Creates or updates one header-and-footer layout: name, default and active
 * flags, the composed page, header and footer (normalized by LetterLayout),
 * its images and the official stamp on the private disk (type decided by
 * sniffing the content). Exactly one layout per institution is the default;
 * the first one an institution creates becomes it. Also duplicates and
 * deletes layouts.
 */
final class SaveLetterhead
{
    public const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> sniffed MIME => extension */
    public const ALLOWED = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'];

    public function __construct(private readonly LettersAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated request data (name, is_default, active, remove_images, image_names, remove_stamp)
     * @param  array<string, mixed>  $layout  decoded page, header and footer
     * @param  array<string, UploadedFile>  $newImages  keyed by the image key the layout's items use
     */
    public function execute(?LetterLetterhead $letterhead, ?string $organizationId, array $data, array $layout, array $newImages, ?UploadedFile $stamp, User $actor): LetterLetterhead
    {
        $creating = $letterhead === null;
        $letterhead ??= new LetterLetterhead(['organization_id' => $organizationId, 'created_by' => $actor->id]);
        $owner = $letterhead->organization_id;
        $old = $creating ? null : $letterhead->only(['name', 'is_default', 'active']);

        // Images: drop the removed ones, add the new ones under the key the editor gave them.
        $images = $letterhead->imageList();
        $remove = array_map('strval', (array) ($data['remove_images'] ?? []));
        foreach ($images as $i => $image) {
            if (in_array($image['key'], $remove, true) || isset($newImages[$image['key']])) {
                Storage::disk(LetterLetterhead::DISK)->delete($image['path']);
                unset($images[$i]);
            }
        }
        $names = (array) ($data['image_names'] ?? []);
        foreach ($newImages as $key => $file) {
            $images[] = ['key' => (string) $key, 'path' => $this->store($file, 'new_images'), 'name' => mb_substr((string) ($names[$key] ?? $file->getClientOriginalName()), 0, 120)];
        }
        $images = array_values($images);
        if (count($images) > LetterLayout::MAX_IMAGES) {
            throw ValidationException::withMessages(['new_images' => __('letters.validation.layout_images', ['max' => LetterLayout::MAX_IMAGES])]);
        }
        $keys = array_column($images, 'key');

        if (! empty($data['remove_stamp']) && $letterhead->stamp_path !== null) {
            Storage::disk(LetterLetterhead::DISK)->delete($letterhead->stamp_path);
            $letterhead->stamp_path = null;
        }
        if ($stamp !== null) {
            $stored = $this->store($stamp, 'stamp');
            if ($letterhead->stamp_path !== null) {
                Storage::disk(LetterLetterhead::DISK)->delete($letterhead->stamp_path);
            }
            $letterhead->stamp_path = $stored;
        }

        $letterhead->fill([
            'name' => trim((string) $data['name']),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'page' => LetterLayout::page((array) ($layout['page'] ?? [])),
            'header' => LetterLayout::section((array) ($layout['header'] ?? []), $keys),
            'footer' => LetterLayout::section((array) ($layout['footer'] ?? []), $keys),
            'images' => $images,
            'updated_by' => $actor->id,
        ]);
        $hasDefault = LetterLetterhead::query()->where(fn ($q) => $owner === null ? $q->whereNull('organization_id') : $q->where('organization_id', $owner))
            ->where('is_default', true)->when(! $creating, fn ($q) => $q->whereKeyNot($letterhead->id))->exists();
        $letterhead->is_default = ! empty($data['is_default']) || ! $hasDefault;

        DB::transaction(function () use ($letterhead, $owner): void {
            $letterhead->save();
            if ($letterhead->is_default) {
                $this->onlyDefault($letterhead, $owner);
            }
        });

        $this->audit->record(AuditEventType::LetterLetterheadSaved, $actor, $letterhead, $owner, ['name' => $letterhead->name, 'default' => $letterhead->is_default, 'active' => $letterhead->active, 'platform' => $owner === null], null, $old);

        return $letterhead;
    }

    public function makeDefault(LetterLetterhead $letterhead, User $actor): void
    {
        DB::transaction(function () use ($letterhead): void {
            $letterhead->forceFill(['is_default' => true, 'active' => true])->save();
            $this->onlyDefault($letterhead, $letterhead->organization_id);
        });
        $this->audit->record(AuditEventType::LetterLetterheadSaved, $actor, $letterhead, $letterhead->organization_id, ['name' => $letterhead->name, 'default' => true]);
    }

    /** A copy of the layout, files included, under a new name; never the default. */
    public function duplicate(LetterLetterhead $source, string $name, User $actor): LetterLetterhead
    {
        $images = array_map(fn (array $image): array => [...$image, 'path' => $this->copy($image['path'])], $source->imageList());
        $copy = LetterLetterhead::query()->create([
            'organization_id' => $source->organization_id, 'name' => mb_substr($name, 0, 120), 'is_default' => false, 'active' => true,
            'page' => $source->page, 'header' => $source->header, 'footer' => $source->footer, 'images' => $images,
            'stamp_path' => $source->stamp_path !== null ? $this->copy($source->stamp_path) : null,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $this->audit->record(AuditEventType::LetterLetterheadSaved, $actor, $copy, $copy->organization_id, ['name' => $copy->name, 'copied_from' => $source->id]);

        return $copy;
    }

    /** Deletes a layout no letter has used; a used one can only be deactivated. */
    public function delete(LetterLetterhead $letterhead, User $actor): void
    {
        if ($letterhead->letters()->exists()) {
            throw ValidationException::withMessages(['letterhead' => __('letters.validation.layout_in_use')]);
        }
        $owner = $letterhead->organization_id;
        $paths = [...array_column($letterhead->imageList(), 'path'), ...array_filter([$letterhead->stamp_path])];
        $wasDefault = $letterhead->is_default;
        DB::transaction(function () use ($letterhead, $owner, $wasDefault): void {
            $letterhead->delete();
            if ($wasDefault) {
                LetterLetterhead::query()->where(fn ($q) => $owner === null ? $q->whereNull('organization_id') : $q->where('organization_id', $owner))
                    ->where('active', true)->orderBy('created_at')->first()?->forceFill(['is_default' => true])->save();
            }
        });
        Storage::disk(LetterLetterhead::DISK)->delete($paths);
        $this->audit->record(AuditEventType::LetterLetterheadSaved, $actor, $letterhead, $owner, ['name' => $letterhead->name, 'deleted' => true]);
    }

    private function onlyDefault(LetterLetterhead $letterhead, ?string $owner): void
    {
        LetterLetterhead::query()->where(fn ($q) => $owner === null ? $q->whereNull('organization_id') : $q->where('organization_id', $owner))
            ->whereKeyNot($letterhead->id)->where('is_default', true)->update(['is_default' => false]);
    }

    private function store(UploadedFile $file, string $field): string
    {
        $path = $file->getRealPath();
        $mime = $path !== false ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        $size = (int) $file->getSize();
        if (! isset(self::ALLOWED[$mime]) || $size <= 0 || $size > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages([$field => __('letters.validation.logo_type', ['max' => self::MAX_IMAGE_BYTES / 1024 / 1024])]);
        }
        $stored = $file->storeAs('letters/letterheads', Str::random(40).'.'.self::ALLOWED[$mime], LetterLetterhead::DISK);
        if ($stored === false) {
            throw ValidationException::withMessages([$field => __('letters.validation.logo_type', ['max' => self::MAX_IMAGE_BYTES / 1024 / 1024])]);
        }

        return $stored;
    }

    private function copy(string $path): string
    {
        $target = 'letters/letterheads/'.Str::random(40).'.'.pathinfo($path, PATHINFO_EXTENSION);
        Storage::disk(LetterLetterhead::DISK)->copy($path, $target);

        return $target;
    }
}
