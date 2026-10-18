<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\RegisterMemberData;
use App\Services\Auth\RegistrationPolicies;
use App\Services\Members\NormalizePhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $changes = [];

        foreach (['full_name', 'city', 'gender'] as $field) {
            if (is_string($this->input($field))) {
                $changes[$field] = trim($this->input($field));
            }
        }

        if (is_string($this->input('email'))) {
            $changes['email'] = mb_strtolower(trim($this->input('email')), 'UTF-8');
        }

        if (is_string($this->input('phone'))) {
            $changes['phone'] = app(NormalizePhoneNumber::class)->handle($this->input('phone'));
        }

        $this->merge($changes);
    }

    public function rules(): array
    {
        $policies = app(RegistrationPolicies::class)->current();

        return [
            'full_name' => ['bail', 'required', 'string', 'min:2', 'max:150'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254'],
            'password' => [
                'bail', 'required', 'string', 'min:12', 'confirmed',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    // Bcrypt's input limit must not silently truncate a password.
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('Password terlalu panjang. Gunakan password yang lebih pendek.');
                    }
                    if (is_string($value) && str_contains($value, "\0")) {
                        $fail('Password memuat karakter yang tidak didukung.');
                    }
                },
            ],
            'password_confirmation' => ['required', 'string'],
            'birth_date' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['bail', 'required', 'string', 'max:32', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'city' => ['bail', 'required', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:32'],
            'privacy_notice_accepted' => ['required', 'boolean', 'accepted'],
            'privacy_notice_version' => ['required', 'string', Rule::in([$policies['privacy_notice']])],
            'terms_accepted' => ['required', 'boolean', 'accepted'],
            'terms_version' => ['required', 'string', Rule::in([$policies['terms']])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [
                'full_name', 'email', 'password', 'password_confirmation', 'birth_date', 'phone', 'city', 'gender',
                'privacy_notice_accepted', 'privacy_notice_version', 'terms_accepted', 'terms_version',
            ];

            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'full_name.min' => 'Nama lengkap minimal 2 karakter.',
            'max' => ':attribute terlalu panjang.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'password.min' => 'Password minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'birth_date.date_format' => 'Masukkan tanggal lahir yang valid dengan format YYYY-MM-DD.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'phone.regex' => 'Masukkan nomor telepon yang valid, misalnya 081234567890 atau +6281234567890.',
            'accepted' => ':attribute wajib diakui sebelum registrasi.',
            'boolean' => ':attribute harus berupa pilihan benar atau salah.',
            'in' => 'Versi kebijakan telah berubah. Muat ulang formulir registrasi.',
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'Nama lengkap', 'email' => 'Email', 'password' => 'Password',
            'password_confirmation' => 'Konfirmasi password', 'birth_date' => 'Tanggal lahir',
            'phone' => 'Nomor telepon', 'city' => 'Kota/domisili', 'gender' => 'Jenis kelamin',
            'privacy_notice_accepted' => 'Pemberitahuan privasi', 'privacy_notice_version' => 'Versi pemberitahuan privasi',
            'terms_accepted' => 'Ketentuan komunitas', 'terms_version' => 'Versi ketentuan komunitas',
        ];
    }

    public function toData(): RegisterMemberData
    {
        $data = $this->validated();

        return new RegisterMemberData(
            fullName: $data['full_name'], email: $data['email'], password: $data['password'],
            birthDate: $data['birth_date'], phone: $data['phone'], city: $data['city'],
            gender: $data['gender'] ?? null, privacyNoticeVersion: $data['privacy_notice_version'],
            termsVersion: $data['terms_version'],
        );
    }
}
