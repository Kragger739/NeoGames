<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind the `admin` middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'min:2', 'max:24', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($targetId),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
            'email_verified' => ['required', 'boolean'],
            'is_admin' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $target = $this->route('user');
            if ($target->is($this->user()) && $this->boolean('is_admin') === false) {
                $validator->errors()->add('is_admin', 'You cannot remove your own admin access.');
            }
        });
    }
}
