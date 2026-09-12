<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Models\IdCardTemplate;
use App\Services\IdCards\IdCardBackPhoto;
use App\Services\IdCards\IdCardHeaderContent;
use App\Services\IdCards\IdCardLayoutElement;
use App\Services\IdCards\IdCardTextStyle;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveIdCardTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->route('template') instanceof IdCardTemplate ? 'update' : 'create';

        return $this->user()?->can('id_card_templates.'.$ability) === true;
    }

    public function rules(): array
    {
        $limit = max(1, (int) app(SystemSettingsService::class)->get('security', 'max_upload_size_mb', 10)) * 1024;
        $pngBase = ['bail', 'nullable', 'file', 'image', 'mimes:png', 'mimetypes:image/png', 'extensions:png', 'max:'.$limit];
        // Background artwork covers the whole card, so it needs real resolution.
        $png = [...$pngBase, 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'];
        // A header logo takes any size or aspect ratio: the renderer scales it
        // into its own layout box, so the file's own dimensions do not matter.
        // Content is still verified as a real PNG and capped by the size limit.
        $logoPng = $pngBase;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/', Rule::unique('id_card_templates', 'code')->ignore($this->route('template'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'width_mm' => ['nullable', 'numeric', 'between:30,200'],
            'height_mm' => ['nullable', 'numeric', 'between:30,200'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_default' => ['required', 'boolean'],
            'front_background' => $png,
            'back_background' => $png,
            'logo_primary' => $logoPng,
            'logo_secondary' => $logoPng,
            'remove_front_background' => ['sometimes', 'boolean'],
            'remove_back_background' => ['sometimes', 'boolean'],
            'remove_logo_primary' => ['sometimes', 'boolean'],
            'remove_logo_secondary' => ['sometimes', 'boolean'],
            ...$this->styleRules(),
            ...$this->layoutRules(),
            ...$this->headerRules(),
            ...$this->backPhotoRules(),
        ];
    }

    /**
     * The back-face photo watermark. Every value is optional; the renderer
     * inlines opacity and contrast into the SVG, so both are range-checked here
     * as well as clamped on read.
     *
     * @return array<string, array<int, mixed>>
     */
    private function backPhotoRules(): array
    {
        return [
            'back_photo_config' => ['nullable', 'array:show,opacity,contrast,fit,background_color'],
            'back_photo_config.show' => ['sometimes', 'boolean'],
            'back_photo_config.opacity' => ['sometimes', 'numeric', 'between:'.IdCardBackPhoto::MIN_OPACITY.','.IdCardBackPhoto::MAX_OPACITY],
            'back_photo_config.contrast' => ['sometimes', 'numeric', 'between:'.IdCardBackPhoto::MIN_CONTRAST.','.IdCardBackPhoto::MAX_CONTRAST],
            'back_photo_config.fit' => ['sometimes', Rule::in(IdCardBackPhoto::FITS)],
            'back_photo_config.background_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D'],
        ];
    }

    /**
     * Front-header content. Every text field is an optional override, so an
     * empty string is valid and means "inherit the system setting".
     *
     * @return array<string, array<int, mixed>>
     */
    private function headerRules(): array
    {
        $rules = [
            'header_config' => ['nullable', 'array:'.implode(',', [
                ...array_keys(IdCardHeaderContent::FIELDS), 'show_logo', 'show_secondary_logo',
            ])],
            'header_config.show_logo' => ['sometimes', 'boolean'],
            'header_config.show_secondary_logo' => ['sometimes', 'boolean'],
        ];
        foreach (IdCardHeaderContent::FIELDS as $field => $meta) {
            $rules['header_config.'.$field] = ['nullable', 'string', 'max:'.$meta['max']];
        }

        return $rules;
    }

    /**
     * Typography for every text role on both sides. Each role is optional; when
     * sent it must be complete and drawn from the allowed sizes/weights.
     *
     * @return array<string, array<int, mixed>>
     */
    private function styleRules(): array
    {
        $rules = [
            'text_style_config' => ['nullable', 'array:'.implode(',', array_keys(IdCardTextStyle::ROLES))],
        ];
        foreach (IdCardTextStyle::ROLES as $side => $roles) {
            $rules['text_style_config.'.$side] = ['nullable', 'array:'.implode(',', array_keys($roles))];
            foreach (array_keys($roles) as $role) {
                $field = 'text_style_config.'.$side.'.'.$role;
                $rules[$field] = ['nullable', 'array:color,font_size,font_weight'];
                $rules[$field.'.color'] = ['required_with:'.$field, 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D'];
                $rules[$field.'.font_size'] = ['required_with:'.$field, Rule::in(IdCardTextStyle::SIZES)];
                $rules[$field.'.font_weight'] = ['required_with:'.$field, Rule::in(IdCardTextStyle::WEIGHTS)];
            }
        }

        return $rules;
    }

    /**
     * Where each element sits, as percentages of the card. Every element is
     * optional; when sent it must be complete and inside the card, because the
     * renderer clips silently at the card edge rather than reporting an error.
     *
     * @return array<string, array<int, mixed>>
     */
    private function layoutRules(): array
    {
        $rules = [
            'layout_config' => ['nullable', 'array:'.implode(',', array_keys(IdCardLayoutElement::ELEMENTS))],
        ];
        foreach (IdCardLayoutElement::ELEMENTS as $side => $elements) {
            $rules['layout_config.'.$side] = ['nullable', 'array:'.implode(',', array_keys($elements))];
            foreach (array_keys($elements) as $element) {
                $field = 'layout_config.'.$side.'.'.$element;
                $rules[$field] = ['nullable', 'array:x,y,w,h'];
                foreach (['x', 'y'] as $axis) {
                    $rules[$field.'.'.$axis] = ['required_with:'.$field, 'numeric', 'between:0,100'];
                }
                foreach (['w', 'h'] as $size) {
                    $rules[$field.'.'.$size] = ['required_with:'.$field, 'numeric', 'between:1,100'];
                }
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $template = $this->route('template');
            $wasDefault = $template instanceof IdCardTemplate && $template->is_default;
            if ($this->boolean('is_default') !== $wasDefault && ! $this->user()->can('id_card_templates.set_default')) {
                abort(403);
            }
            if ($this->boolean('is_default') && $this->input('status') !== 'active') {
                $validator->errors()->add('status', __('id-card-templates.default_active'));
            }
            $portrait = $this->input('orientation') === 'portrait';
            $width = (float) ($this->input('width_mm') ?: ($portrait ? 54 : 85.6));
            $height = (float) ($this->input('height_mm') ?: ($portrait ? 85.6 : 54));
            if (($portrait && $width > $height) || (! $portrait && $width < $height)) {
                $validator->errors()->add('width_mm', __('id-card-templates.dimensions_orientation'));
            }

            // An element that runs past the card edge is clipped silently by the
            // renderer, so reject it here rather than let it disappear.
            foreach ((array) $this->input('layout_config', []) as $side => $elements) {
                foreach ((array) $elements as $element => $box) {
                    if (! is_array($box)) {
                        continue;
                    }
                    $field = 'layout_config.'.$side.'.'.$element;
                    foreach ([['x', 'w'], ['y', 'h']] as [$start, $size]) {
                        if (((float) ($box[$start] ?? 0)) + ((float) ($box[$size] ?? 0)) > 100.0) {
                            $validator->errors()->add($field.'.'.$size, __('id-card-templates.layout_bounds'));
                        }
                    }
                }
            }
        }];
    }

    public function messages(): array
    {
        $messages = [];
        foreach (['front_background', 'back_background', 'logo_primary', 'logo_secondary'] as $field) {
            foreach (['image', 'mimes', 'mimetypes', 'extensions'] as $rule) {
                $messages[$field.'.'.$rule] = __('id-card-templates.png_only');
            }
        }

        return $messages;
    }
}
