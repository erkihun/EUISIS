<?php

declare(strict_types=1);

namespace App\Http\Requests\IdCards;

use App\Models\IdCardTemplate;
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
        $png = ['bail', 'nullable', 'file', 'image', 'mimes:png', 'mimetypes:image/png', 'extensions:png', 'max:'.$limit,
            'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'];

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
            'remove_front_background' => ['sometimes', 'boolean'],
            'remove_back_background' => ['sometimes', 'boolean'],
            ...$this->styleRules(),
            ...$this->layoutRules(),
        ];
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
        foreach (['front_background', 'back_background'] as $field) {
            foreach (['image', 'mimes', 'mimetypes', 'extensions'] as $rule) {
                $messages[$field.'.'.$rule] = __('id-card-templates.png_only');
            }
        }

        return $messages;
    }
}
