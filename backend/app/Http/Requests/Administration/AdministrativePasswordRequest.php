<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

final class AdministrativePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user('web'))->allows($this->route('userId') === null ? 'changePassword' : 'provision', User::class);
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['bail', 'required', 'string', 'min:12', 'confirmed', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('Password maksimal 72 byte dan tidak boleh memuat karakter null.');
                }
            }],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (count($this->query()) > 0 || array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Password akun Anda saat ini wajib diisi.',
            'password.min' => 'Password minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi password tidak sama.',
        ];
    }
}
