<?php

namespace App\Http\Requests;

use App\Enums\DatasetType;
use App\Enums\DdfLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatasetRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'type' => ['required', Rule::in(DatasetType::values())],
            // DDF datasets carry a language (questions inherit it); Songle
            // datasets don't.
            'language' => [
                'nullable',
                'required_if:type,ddf',
                Rule::in(array_column(DdfLanguage::cases(), 'value')),
            ],
        ];
    }
}
