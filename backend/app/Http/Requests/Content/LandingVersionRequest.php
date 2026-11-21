<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LandingVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0', 'max:4294967294']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = $this instanceof SaveLandingDraftRequest ? ['version', 'content'] : ['version'];
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
        }];
    }

    public function messages(): array
    {
        return ['version.required' => 'Versi draft diperlukan. Muat ulang draft terlebih dahulu.', 'version.integer' => 'Versi draft tidak valid.', 'version.min' => 'Versi draft tidak valid.', 'version.max' => 'Versi draft tidak valid.', 'content.required' => 'Konten draft diperlukan.', 'content.array' => 'Struktur konten draft tidak valid.'];
    }
}
