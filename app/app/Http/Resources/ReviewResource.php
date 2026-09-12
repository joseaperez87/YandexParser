<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'author' => $this->author,
            'rating' => $this->rating,
            'text' => $this->text,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
