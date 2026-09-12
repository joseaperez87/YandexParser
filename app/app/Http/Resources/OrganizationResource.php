<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'business_id' => $this->business_id,
            'title' => $this->title,
            'address' => $this->address,
            'rating' => $this->rating,
            'ratings_count' => $this->ratings_count,
            'reviews_count' => $this->reviews_count,
            'last_parsed_at' => $this->last_parsed_at?->toIso8601String(),
            'parse_status' => $this->parse_status,
            'parse_error' => $this->parse_error,
        ];
    }
}
