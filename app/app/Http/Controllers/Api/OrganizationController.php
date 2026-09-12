<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\ParseRunResource;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->current($request);

        return response()->json([
            'data' => $organization === null ? null : new OrganizationResource($organization),
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        // Сброс данных предыдущей организации — только при смене бизнеса.
        // Refresh той же карточки (тот же business_id) сохраняет старые агрегаты.
        $existing = Organization::where('user_id', $request->user()->id)->latest('id')->first();
        $businessChanged = $existing !== null
            && (string) $existing->business_id !== (string) $request->businessId();

        $attributes = [
            'url' => $request->canonicalUrl(),
            'business_id' => $request->businessId(),
            'parse_status' => 'queued',
            'parse_error' => null,
        ];

        if ($businessChanged) {
            $attributes += [
                'title' => null,
                'address' => null,
                'rating' => null,
                'ratings_count' => 0,
                'reviews_count' => 0,
                'last_parsed_at' => null,
            ];
        }

        $organization = Organization::updateOrCreate(
            ['user_id' => $request->user()->id],
            $attributes,
        );

        if ($businessChanged) {
            Review::where('organization_id', $organization->id)->delete();
        }

        ParseOrganizationJob::dispatch($organization);

        return (new OrganizationResource($organization))
            ->response()
            ->setStatusCode($organization->wasRecentlyCreated ? 201 : 200);
    }

    public function refresh(Request $request): JsonResponse
    {
        $organization = $this->current($request);

        if ($organization === null) {
            return response()->json(['message' => 'No organization has been saved yet.'], 404);
        }

        if ($this->hasActiveParse($organization)) {
            return response()->json(['message' => 'A parse is already in progress.'], 409);
        }

        $organization->update([
            'parse_status' => 'queued',
            'parse_error' => null,
        ]);

        ParseOrganizationJob::dispatch($organization);

        return (new OrganizationResource($organization))
            ->response()
            ->setStatusCode(202);
    }

    public function parseStatus(Request $request): JsonResponse
    {
        $organization = $this->current($request);

        if ($organization === null) {
            return response()->json(['data' => null]);
        }

        $run = $organization->parseRuns()->latest('id')->first();

        return response()->json([
            'data' => [
                'parse_status' => $organization->parse_status,
                'parse_error' => $organization->parse_error,
                'run' => $run === null ? null : new ParseRunResource($run),
            ],
        ]);
    }

    private function current(Request $request): ?Organization
    {
        return Organization::where('user_id', $request->user()->id)->latest('id')->first();
    }

    private function hasActiveParse(Organization $organization): bool
    {
        if (in_array($organization->parse_status, ['queued', 'running'], true)) {
            return true;
        }

        return $organization->parseRuns()
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
