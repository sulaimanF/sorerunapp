<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AcceptAdminInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A public proof never replaces another browser identity, even temporarily.
        if ($this->user('web') !== null) {
            throw new ApiProblem('INVITATION_SESSION_CONFLICT', 'Keluar dari akun yang sedang aktif, lalu buka ulang tautan undangan.', 403);
        }

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
            'invitation_id' => ['required', 'string', 'uuid'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
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
        return ['password.min' => 'Password minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi password tidak sama.'];
    }
}
