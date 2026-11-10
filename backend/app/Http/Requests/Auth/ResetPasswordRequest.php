<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Closure;

class ResetPasswordRequest extends ForgotPasswordRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'token' => ['bail', 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'password' => ['bail', 'required', 'string', 'min:12', 'confirmed',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail('Password maksimal 72 byte dan tidak boleh memuat karakter null.');
                    }
                },
            ],
            'password_confirmation' => ['required', 'string'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'token.required' => 'Tautan reset tidak valid. Minta tautan baru.',
            'token.regex' => 'Tautan reset tidak valid. Minta tautan baru.',
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
        ]);
    }
}
