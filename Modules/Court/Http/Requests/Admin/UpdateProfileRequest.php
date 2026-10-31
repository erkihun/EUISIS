<?php

declare(strict_types=1);

namespace Modules\Court\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The profile page (Profile/Edit, from EUISIS) names the photo
     * `profile_photo` and the phone `phone_number`; court-ms stores them as
     * `avatar` and `phone`. Empty optional fields clear the value.
     */
    protected function prepareForValidation(): void
    {
        if ($this->hasFile('profile_photo') && ! $this->hasFile('avatar')) {
            $this->files->set('avatar', $this->file('profile_photo'));
        }
        if ($this->has('phone_number') && ! $this->has('phone')) {
            $this->merge(['phone' => $this->input('phone_number')]);
        }
        foreach (['phone', 'gender', 'national_id'] as $optional) {
            if ($this->has($optional) && $this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('court_staff', 'email')->ignore($this->user()?->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9]{7,15}$/'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'national_id' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]{10,16}$/'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'signature' => ['prohibited'],
            'remove_signature' => ['prohibited'],
        ];
    }

    /** The page shows each error under the field it sent. */
    public function validationData(): array
    {
        return parent::validationData();
    }

    /** @return array<string, string> */
    protected function passedValidation(): void
    {
    }

    /**
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $messages = $validator->errors();
        if ($messages->has('phone') && ! $messages->has('phone_number')) {
            $messages->add('phone_number', $messages->first('phone'));
        }
        if ($messages->has('avatar') && ! $messages->has('profile_photo')) {
            $messages->add('profile_photo', $messages->first('avatar'));
        }

        parent::failedValidation($validator);
    }
}
