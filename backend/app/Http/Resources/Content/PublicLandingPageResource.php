<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicLandingPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $publication = $this->resource['publication'];

        return ['publication' => $publication === null ? null : [
            'version' => $publication['version'], 'published_at' => $publication['published_at'],
            'content' => $publication['content'], 'media' => $publication['media'],
        ]];
    }
}
