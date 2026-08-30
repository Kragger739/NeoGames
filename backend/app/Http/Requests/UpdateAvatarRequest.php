<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 'image' confirms it's a genuinely decodable image (not just a
            // renamed extension), on top of the explicit format allowlist -
            // 2MB cap is generous for a profile picture without inviting
            // abuse.
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }
}
