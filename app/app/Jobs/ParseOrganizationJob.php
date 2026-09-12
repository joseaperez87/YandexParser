<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Services\YandexMaps\Exceptions\EmptyResponseException;
use App\Services\YandexMaps\Exceptions\SourceChangedException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\ParserResult;
use App\Services\YandexMaps\YandexMapsParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ParseOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    private const CHUNK_SIZE = 200;

    public function __construct(
        public readonly Organization $organization,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(YandexMapsParser $parser): void
    {
        $organization = $this->organization;

        $run = $organization->parseRuns()->create([
            'status' => 'running',
            'progress' => 0,
            'total' => 0,
            'started_at' => now(),
        ]);
        $organization->update(['parse_status' => 'running', 'parse_error' => null]);

        try {
            $result = $parser->parse($organization, new ParseContext(
                onProgress: function (int $collected, int $total) use ($run): void {
                    $run->update(['progress' => $collected, 'total' => $total]);
                },
            ));
        } catch (SourceChangedException|EmptyResponseException $exception) {
            $this->recordTerminalFailure($organization, $run, $exception);

            return;
        }

        $this->persist($organization, $run, $result);
    }

    public function failed(Throwable $exception): void
    {
        $organization = $this->organization;

        $organization->parseRuns()
            ->where('status', 'running')
            ->latest('id')
            ->first()
            ?->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

        $organization->update([
            'parse_status' => 'failed',
            'parse_error' => $exception->getMessage(),
        ]);

        Log::error('Yandex Maps parsing job failed.', [
            'organization_id' => $organization->id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function persist(Organization $organization, ParseRun $run, ParserResult $result): void
    {
        $rows = $this->reviewRows($organization, $result->reviews);

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            Review::upsert(
                $chunk,
                ['organization_id', 'external_id'],
                ['author', 'rating', 'text', 'published_at', 'updated_at'],
            );
        }

        if ($result->status !== 'partial') {
            $this->deleteOrphans($organization, $rows);
        }

        $organization->update([
            'business_id' => $result->businessId ?? $organization->business_id,
            'title' => $result->title ?? $organization->title,
            'address' => $result->address ?? $organization->address,
            'rating' => $result->rating,
            'ratings_count' => $result->ratingsCount,
            'reviews_count' => $result->reviewsCount,
            'parse_status' => $result->status === 'partial' ? 'partial' : 'ready',
            'parse_error' => null,
            'last_parsed_at' => now(),
        ]);

        if ($result->status === 'partial') {
            Log::warning('Yandex Maps parse is partial: collected count differs from the source aggregate.', [
                'organization_id' => $organization->id,
                'collected' => count($result->reviews),
                'reviews_count' => $result->reviewsCount,
                'warnings' => $result->warnings,
            ]);
        }

        $snapshot = $organization->snapshots()->create([
            'parse_run_id' => $run->id,
            'rating' => $result->rating,
            'ratings_count' => $result->ratingsCount,
            'reviews_count' => $result->reviewsCount,
            'payload' => [
                'status' => $result->status,
                'warnings' => $result->warnings,
                'business_id' => $result->businessId,
                'collected_reviews' => count($result->reviews),
            ],
        ]);

        $this->recordChanges($organization, $snapshot);

        $run->update([
            'status' => $result->status === 'partial' ? 'partial' : 'done',
            'progress' => count($result->reviews),
            'total' => $result->reviewsCount,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     * @return list<array<string, mixed>>
     */
    private function reviewRows(Organization $organization, array $reviews): array
    {
        $now = now();
        $rows = [];

        foreach ($reviews as $review) {
            if (($review['external_id'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                'organization_id' => $organization->id,
                'external_id' => (string) $review['external_id'],
                'author' => $review['author'] ?? null,
                'rating' => $review['rating'] ?? null,
                'text' => $review['text'] ?? null,
                'published_at' => $this->toDateTime($review['published_at'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function deleteOrphans(Organization $organization, array $rows): void
    {
        Review::where('organization_id', $organization->id)
            ->whereNotIn('external_id', array_column($rows, 'external_id'))
            ->delete();
    }

    private function recordChanges(Organization $organization, object $snapshot): void
    {
        $previous = $organization->snapshots()
            ->whereKeyNot($snapshot->getKey())
            ->latest('id')
            ->first();

        if ($previous === null) {
            return;
        }

        foreach (['rating', 'ratings_count', 'reviews_count'] as $field) {
            $old = $previous->{$field};
            $new = $snapshot->{$field};

            if ((string) $old === (string) $new) {
                continue;
            }

            $organization->parseChanges()->create([
                'snapshot_id' => $snapshot->getKey(),
                'field' => $field,
                'old' => (string) $old,
                'new' => (string) $new,
            ]);
        }
    }

    private function recordTerminalFailure(Organization $organization, ParseRun $run, Throwable $exception): void
    {
        $status = $exception instanceof SourceChangedException ? 'source_changed' : 'empty';

        $run->update([
            'status' => $status,
            'error' => $exception->getMessage(),
            'finished_at' => now(),
        ]);

        $organization->update([
            'parse_status' => $status,
            'parse_error' => $exception->getMessage(),
        ]);

        Log::warning('Yandex Maps parsing finished with a terminal status.', [
            'organization_id' => $organization->id,
            'status' => $status,
            'error' => $exception->getMessage(),
        ]);
    }

    private function toDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
