<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreRecycleBinRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recycle-bin.restore') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
