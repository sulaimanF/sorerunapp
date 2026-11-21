<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

abstract class AdminAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user('web'))->allows('provision', User::class);
    }

    protected function prepareForValidation(): void
    {
        $changes = [];
        foreach (['full_name', 'email', 'request_key'] as $field) {
            if (is_string($this->input($field))) {
                $changes[$field] = $field === 'full_name' ? trim($this->input($field)) : mb_strtolower(trim($this->input($field)), 'UTF-8');
            }
        }
        $this->merge($changes);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (count($this->query()) > 0 || array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
        }];
    }
}
