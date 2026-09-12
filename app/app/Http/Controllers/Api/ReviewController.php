<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexReviewsRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Organization;
use App\Models\Review;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function index(IndexReviewsRequest $request): AnonymousResourceCollection
    {
        $organization = Organization::where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        $reviews = Review::query()
            ->where('organization_id', $organization?->id ?? 0)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }
}
