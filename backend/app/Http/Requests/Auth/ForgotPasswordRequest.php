<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')), 'UTF-8')]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['bail', 'required', 'string', 'email:rfc', 'max:254']];
    }

    public function messages(): array
    {
        return ['email.required' => 'Email wajib diisi.', 'email.email' => 'Masukkan alamat email yang valid.', 'email.max' => 'Email terlalu panjang.'];
    }
}
