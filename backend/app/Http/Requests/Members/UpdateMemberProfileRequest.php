<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Data\Members\UpdateMemberProfileData;
use App\Services\Members\NormalizePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    protected function prepareForValidation(): void
    {
        $changes = [];
        foreach (['full_name', 'city', 'gender'] as $field) {
            if (is_string($this->input($field))) {
                $changes[$field] = trim($this->input($field));
            }
        }
        if (is_string($this->input('phone'))) {
            $changes['phone'] = app(NormalizePhoneNumber::class)->handle($this->input('phone'));
        }
        $this->merge($changes);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['bail', 'sometimes', 'required', 'string', 'min:2', 'max:150'],
            'birth_date' => ['bail', 'sometimes', 'required', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['bail', 'sometimes', 'required', 'string', 'max:32', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'city' => ['bail', 'sometimes', 'required', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['full_name', 'birth_date', 'phone', 'city', 'gender'];
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
            if (array_intersect(array_keys($this->all()), $allowed) === []) {
                $validator->errors()->add('_request', 'Isi setidaknya satu field profil yang ingin diubah.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.',
            'full_name.min' => 'Nama lengkap minimal 2 karakter.', 'max' => ':attribute terlalu panjang.',
            'birth_date.date_format' => 'Masukkan tanggal lahir yang valid dengan format YYYY-MM-DD.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'phone.regex' => 'Masukkan nomor telepon yang valid, misalnya 081234567890 atau +6281234567890.',
        ];
    }

    public function attributes(): array
    {
        return ['full_name' => 'Nama lengkap', 'birth_date' => 'Tanggal lahir', 'phone' => 'Nomor telepon', 'city' => 'Kota/domisili', 'gender' => 'Jenis kelamin'];
    }

    public function toData(): UpdateMemberProfileData
    {
        return new UpdateMemberProfileData($this->validated());
    }
}
