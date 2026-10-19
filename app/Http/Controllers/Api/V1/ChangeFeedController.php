<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExternalApplication;
use App\Models\Integration\IntegrationEvent;
use App\Services\Integration\Events\ChangeVisibility;
use App\Services\Integration\Events\EventCatalog;
use App\Services\Integration\Events\FeedCursor;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The authenticated partner change feed (docs/integration/change-feed.md).
 *
 *   GET  /api/v1/changes              ordered, cursor-paged events
 *   GET  /api/v1/changes/watermark    current position, for bootstrap
 *   POST /api/v1/changes/reconcile    latest visible sequence per UUID
 *
 * Registered applications only, scope changes.read, plus the domain scope of
 * each event type and the application's organization bound — both applied in
 * SQL before serialisation. Keyset pagination on feed_position; never offset.
 */
class ChangeFeedController extends Controller
{
    public function __construct(private readonly ChangeVisibility $visibility) {}

    public function index(Request $request): JsonResponse
    {
        if (($refusal = $this->refuse($request)) !== null) {
            return $refusal;
        }
        $application = $request->user();

        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('integration.change_feed.max_limit', 500)],
            'event_types' => ['nullable', 'string', 'max:2000'],
            'aggregate_types' => ['nullable', 'string', 'max:500'],
        ]);

        $after = 0;
        if (! empty($validated['cursor'])) {
            $after = FeedCursor::decode($validated['cursor'], (string) $application->getKey());
            if ($after === null) {
                return ApiError::response($request, 400, 'invalid_cursor', 'The cursor is not valid for this application.');
            }
        }

        $allowed = $this->visibility->allowedTypes($application);
        $types = $allowed;
        if (! empty($validated['event_types'])) {
            $requested = array_filter(array_map('trim', explode(',', $validated['event_types'])));
            $types = array_values(array_intersect($allowed, $requested));
        }
        if (! empty($validated['aggregate_types'])) {
            $aggregates = array_filter(array_map('trim', explode(',', $validated['aggregate_types'])));
            $types = array_values(array_filter($types, fn (string $type): bool => in_array(EventCatalog::aggregate($type), $aggregates, true)));
        }

        $limit = (int) ($validated['limit'] ?? config('integration.change_feed.default_limit', 100));
        // Everything up to this position is committed and numbered; nothing
        // can later appear below it, so a short page may advance to it.
        $watermark = (int) DB::table('integration_feed_state')->where('id', 1)->value('last_position');

        $query = IntegrationEvent::query()
            ->whereNotNull('feed_position')
            ->where('feed_position', '>', $after)
            ->where('feed_position', '<=', $watermark);
        $this->visibility->constrain($query, $application, $types);

        $rows = $query->orderBy('feed_position')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $next = $hasMore ? (int) $rows->last()->feed_position : max($after, $watermark);

        DB::table('integration_feed_consumers')->upsert(
            [['external_application_id' => $application->getKey(), 'last_position' => $after, 'last_batch_size' => $rows->count(), 'last_read_at' => Carbon::now()]],
            ['external_application_id'],
            ['last_position', 'last_batch_size', 'last_read_at'],
        );

        return response()->json([
            'data' => $rows->map(fn (IntegrationEvent $event): array => $this->visibility->present($event, $application))->values()->all(),
            'next_cursor' => FeedCursor::encode($next, (string) $application->getKey()),
            'has_more' => $hasMore,
            'request_id' => ApiError::requestId($request),
        ]);
    }

    public function watermark(Request $request): JsonResponse
    {
        if (($refusal = $this->refuse($request)) !== null) {
            return $refusal;
        }

        $state = DB::table('integration_feed_state')->where('id', 1)->first();

        return response()->json([
            'cursor' => FeedCursor::encode((int) ($state->last_position ?? 0), (string) $request->user()->getKey()),
            'as_of' => $state?->updated_at !== null ? Carbon::parse($state->updated_at)->toIso8601ZuluString() : null,
            'request_id' => ApiError::requestId($request),
        ]);
    }

    /**
     * Latest visible sequence per aggregate, so a partner can detect it is
     * behind (missing events) without a whole-database dump.
     */
    public function reconcile(Request $request): JsonResponse
    {
        if (($refusal = $this->refuse($request)) !== null) {
            return $refusal;
        }
        $application = $request->user();
        $aggregates = array_values(array_unique(array_map(fn (string $type): string => EventCatalog::aggregate($type), EventCatalog::types())));

        $validated = $request->validate([
            'aggregate_type' => ['required', 'string', 'in:'.implode(',', $aggregates)],
            'uuids' => ['required', 'array', 'min:1', 'max:'.(int) config('integration.change_feed.reconcile_max', 100)],
            'uuids.*' => ['required', 'string', 'max:36'],
        ]);

        $types = array_values(array_filter($this->visibility->allowedTypes($application), fn (string $type): bool => EventCatalog::aggregate($type) === $validated['aggregate_type']));

        $query = IntegrationEvent::query()
            ->whereNotNull('feed_position')
            ->where('aggregate_type', $validated['aggregate_type'])
            ->whereIn('aggregate_uuid', $validated['uuids']);
        $this->visibility->constrain($query, $application, $types);

        $latest = $query->orderBy('sequence')->get()->keyBy('aggregate_uuid');

        return response()->json([
            'aggregate_type' => $validated['aggregate_type'],
            'data' => collect($validated['uuids'])->map(function (string $uuid) use ($latest): array {
                $event = $latest->get($uuid);

                return $event === null
                    ? ['aggregate_uuid' => $uuid, 'found' => false]
                    : [
                        'aggregate_uuid' => $uuid,
                        'found' => true,
                        'sequence' => $event->sequence,
                        'last_event_id' => $event->event_id,
                        'last_event_type' => $event->event_type,
                        'change_kind' => $event->change_kind,
                        'occurred_at' => $event->occurred_at?->toIso8601ZuluString('microsecond'),
                    ];
            })->values()->all(),
            'request_id' => ApiError::requestId($request),
        ]);
    }

    private function refuse(Request $request): ?JsonResponse
    {
        if (! config('integration.change_feed.enabled')) {
            return ApiError::response($request, 404, 'change_feed_disabled', 'The change feed is not enabled.');
        }
        if (! $request->user() instanceof ExternalApplication) {
            return ApiError::response($request, 403, 'application_required', 'The change feed is available to registered applications only.');
        }

        return null;
    }
}
