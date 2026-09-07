<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required', 'string', 'max:255', 'unique:users,email',
                app()->isProduction() ? 'email:rfc,dns' : 'email:rfc',
            ],
            'password' => ['required', 'string', 'confirmed', Password::defaults()->min(8)->letters()->numbers()],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
