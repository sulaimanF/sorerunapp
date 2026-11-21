<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

final class ListAdminAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user('web'))->allows('provision', User::class);
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->request->count() > 0 || $this->json()->count() > 0 || array_diff(array_keys($this->query()), ['page']) !== []) {
                $validator->errors()->add('_request', 'Permintaan memuat field yang tidak didukung.');
            }
        }];
    }
}
