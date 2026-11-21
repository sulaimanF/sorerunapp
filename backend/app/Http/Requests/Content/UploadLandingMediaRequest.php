<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadLandingMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=16,min_height=16,max_width=6000,max_height=6000']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['image']) !== []) {
                $validator->errors()->add('_request', 'Hanya file gambar yang diperbolehkan.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Pilih gambar yang ingin diunggah.',
            'image.file' => 'Unggah file gambar yang valid.', 'image.image' => 'Isi file bukan gambar yang didukung.',
            'image.mimetypes' => 'Gunakan JPEG, PNG atau WebP.', 'image.mimes' => 'Gunakan JPEG, PNG atau WebP.',
            'image.extensions' => 'Ekstensi file harus JPEG, PNG atau WebP.', 'image.max' => 'Ukuran gambar maksimal 5 MB.',
            'image.dimensions' => 'Dimensi setiap sisi gambar harus 16–6000 px.',
        ];
    }
}
