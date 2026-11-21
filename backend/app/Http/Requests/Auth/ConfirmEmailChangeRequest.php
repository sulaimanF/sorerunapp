<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

class ConfirmEmailChangeRequest extends ForgotPasswordRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'request_id' => ['required', 'string', 'uuid'],
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ]);
    }
}
