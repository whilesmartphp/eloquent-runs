<?php

namespace Whilesmart\Runs\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerController;
use Whilesmart\Runs\Http\Controllers\Concerns\ResolvesRuns;
use Whilesmart\Runs\Http\Requests\StoreRunRequest;
use Whilesmart\Runs\Services\RunService;
use Whilesmart\Runs\Support\RunModels;

class RunController extends Controller
{
    use AuthorizesOwnerController, ResolvesRuns;

    public function __construct(private readonly RunService $runs) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeAccessibleOwners(RunModels::run()::query(), $request->user());

        foreach (['type', 'status', 'execution_mode', 'trigger_name'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        foreach (['owner', 'subject', 'trigger'] as $morph) {
            if ($request->filled("{$morph}_type") && $request->filled("{$morph}_id")) {
                $query->where("{$morph}_type", $request->input("{$morph}_type"))
                    ->where("{$morph}_id", $request->input("{$morph}_id"));
            }
        }

        $runs = $query->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return $this->respond($this->resource('run')::collection($runs)->response()->getData(true));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var StoreRunRequest $validated */
        $validated = app(config('runs.requests.store_run', StoreRunRequest::class));
        $attributes = $validated->validated();

        $user = $request->user();
        if ($user instanceof Model) {
            $attributes['requested_by_type'] = $user->getMorphClass();
            $attributes['requested_by_id'] = (string) $user->getKey();
        }

        $created = $this->runs->create($attributes);
        $data = ['run' => new ($this->resource('run'))($created->run)];

        if ($created->upload) {
            $data['upload'] = [
                'attempt' => new ($this->resource('attempt'))($created->upload->attempt),
                'lease_token' => $created->upload->leaseToken,
            ];
        }

        return $this->respond($data, $created->created ? 201 : 200);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $record = $this->findRun($run);
        $this->authorizeAccessTo($record, $request->user());

        return $this->respond(new ($this->resource('run'))($record));
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $record = $this->findRun($run);
        $this->authorizeAccessTo($record, $request->user());

        return $this->respond(new ($this->resource('run'))($this->runs->cancel($record)));
    }

    public function attempts(Request $request, string $run): JsonResponse
    {
        $record = $this->findRun($run);
        $this->authorizeAccessTo($record, $request->user());

        return $this->respond($this->resource('attempt')::collection($record->attempts()->orderBy('number')->get()));
    }

    /** Artifacts of the accepted attempt (none until one is accepted), or of every attempt with ?all=1. */
    public function artifacts(Request $request, string $run): JsonResponse
    {
        $record = $this->findRun($run);
        $this->authorizeAccessTo($record, $request->user());

        $artifacts = $record->artifacts()
            ->with('attempt')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('attempt_id', $record->accepted_attempt_id))
            ->orderBy('id')
            ->get();

        return $this->respond($this->resource('artifact')::collection($artifacts));
    }

    public function download(Request $request, string $run, string $artifact): StreamedResponse
    {
        $record = $this->findRun($run);
        $this->authorizeAccessTo($record, $request->user());

        $file = $record->artifacts()->where('public_id', $artifact)->firstOrFail();

        return Storage::disk($file->disk)->download($file->storage_key, basename($file->path), [
            'Content-Type' => $file->media_type,
        ]);
    }

    /** Log lines after the ?after cursor, oldest first. */
    public function logs(Request $request, string $run): JsonResponse
    {
        $record = $this->findRun($run);
        $this->authorizeAccessTo($record, $request->user());

        $chunks = $record->logChunks()
            ->with('attempt:id,public_id')
            ->where('id', '>', (int) $request->input('after', 0))
            ->orderBy('id')
            ->limit(min(1000, max(1, (int) $request->input('limit', 500))))
            ->get();

        return $this->respond([
            'chunks' => $chunks->map(fn ($chunk) => [
                'cursor' => $chunk->id,
                'attempt_id' => $chunk->attempt->public_id,
                'sequence' => $chunk->sequence,
                'stream' => $chunk->stream,
                'content' => $chunk->content,
                'occurred_at' => $chunk->occurred_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $chunks->last()?->id ?? (int) $request->input('after', 0),
        ]);
    }
}
