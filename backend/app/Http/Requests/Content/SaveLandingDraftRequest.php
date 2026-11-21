<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

class SaveLandingDraftRequest extends LandingVersionRequest
{
    public function rules(): array
    {
        return parent::rules() + ['content' => ['required', 'array']];
    }
}
