<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->resource['version'], 'content' => $this->resource['content'],
            'published_version' => $this->resource['published_version'], 'published_at' => $this->resource['published_at'],
            'media' => $this->resource['media'],
        ];
    }
}
