<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\LoginMemberData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LoginMemberRequest extends FormRequest
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
        return [
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254'],
            'password' => [
                'bail', 'required', 'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail('Password memuat karakter atau panjang yang tidak didukung.');
                    }
                },
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['email', 'password']) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'email.max' => 'Email terlalu panjang.',
        ];
    }

    public function attributes(): array
    {
        return ['email' => 'Email', 'password' => 'Password'];
    }

    public function toData(): LoginMemberData
    {
        $data = $this->validated();

        return new LoginMemberData(email: $data['email'], password: $data['password']);
    }
}
