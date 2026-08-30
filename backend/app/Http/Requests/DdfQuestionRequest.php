<?php

namespace App\Http\Requests;

use App\Enums\DdfQuestionCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Custom DDF questions match the built-in format exactly: text, a single
 * correct_answer, and one of the fixed categories. Language is inherited from
 * the parent dataset, not sent here.
 */
class DdfQuestionRequest extends FormRequest
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
            'text' => ['required', 'string', 'min:3', 'max:500'],
            'correct_answer' => ['required', 'string', 'min:1', 'max:200'],
            'category' => ['required', Rule::in(array_column(DdfQuestionCategory::cases(), 'value'))],
        ];
    }
}
