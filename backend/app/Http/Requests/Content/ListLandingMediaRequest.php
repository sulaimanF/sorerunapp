<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

class ListLandingMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:100000']];
    }
}
