<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

final class CreateAdminAccountRequest extends AdminAccountRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['bail', 'required', 'string', 'min:2', 'max:150'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254'],
            'request_key' => ['bail', 'required', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama lengkap wajib diisi.', 'full_name.min' => 'Nama lengkap minimal 2 karakter.',
            'full_name.max' => 'Nama lengkap maksimal 150 karakter.', 'email.required' => 'Email wajib diisi.',
            'email.email' => 'Masukkan alamat email yang valid.',
        ];
    }
}
