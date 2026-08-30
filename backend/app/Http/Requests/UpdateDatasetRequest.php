<?php

namespace App\Http\Requests;

use App\Enums\DatasetVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'visibility' => ['sometimes', Rule::in(DatasetVisibility::values())],
        ];
    }
}
