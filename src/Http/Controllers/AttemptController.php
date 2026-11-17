<?php

namespace Whilesmart\Runs\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Whilesmart\Runs\Enums\RunStatus;
use Whilesmart\Runs\Exceptions\InvalidLeaseToken;
use Whilesmart\Runs\Http\Controllers\Concerns\ResolvesRuns;
use Whilesmart\Runs\Services\RunService;

/**
 * Calls made by whoever holds an attempt: a runner that claimed it, the Laravel
 * queue, or a reporter uploading a finished run. The lease token in the
 * X-Lease-Token header is the credential.
 */
class AttemptController extends Controller
{
    use ResolvesRuns;

    public const TOKEN_HEADER = 'X-Lease-Token';

    public function __construct(private readonly RunService $runs) {}

    public function heartbeat(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate(['progress' => ['nullable', 'array'], 'metadata' => ['nullable', 'array']]);
        $record = $this->runs->heartbeat($this->findAttempt($attempt), $this->token($request), $validated['progress'] ?? null, $validated['metadata'] ?? null);

        return $this->respond([
            'attempt' => new ($this->resource('attempt'))($record),
            'cancel_requested' => $record->run->status === RunStatus::Cancelling,
        ]);
    }

    public function logs(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate([
            'chunks' => ['required', 'array', 'max:500'],
            'chunks.*.sequence' => ['required', 'integer', 'min:0'],
            'chunks.*.content' => ['required', 'string'],
            'chunks.*.stream' => ['nullable', 'string', 'max:40'],
            'chunks.*.occurred_at' => ['nullable', 'date'],
        ]);

        $stored = $this->runs->appendLogs($this->findAttempt($attempt), $this->token($request), $validated['chunks']);

        return $this->respond(['stored' => $stored]);
    }

    public function artifact(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'path' => ['required', 'string', 'max:1024'],
            'type' => ['nullable', 'string', 'max:100'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        $artifact = $this->runs->storeArtifact(
            $this->findAttempt($attempt),
            $this->token($request),
            $request->file('file'),
            $validated['path'],
            $validated['type'] ?? null,
            $validated['sha256'] ?? null,
            $validated['metadata'] ?? null,
        );

        return $this->respond(new ($this->resource('artifact'))($artifact->load('attempt')), $artifact->wasRecentlyCreated ? 201 : 200);
    }

    public function complete(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate(['result' => ['present', 'array'], 'metadata' => ['nullable', 'array']]);
        $run = $this->runs->complete($this->findAttempt($attempt), $this->token($request), $validated['result'], $validated['metadata'] ?? null);

        return $this->respond(new ($this->resource('run'))($run));
    }

    public function fail(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate([
            'error_code' => ['required', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $run = $this->runs->fail(
            $this->findAttempt($attempt),
            $this->token($request),
            $validated['error_code'],
            $validated['error_message'] ?? null,
            null,
            $validated['metadata'] ?? null,
        );

        return $this->respond(new ($this->resource('run'))($run));
    }

    /** Declare the files this attempt will upload, so completion waits for all of them. */
    public function manifest(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'max:2000'],
            'files.*.path' => ['required', 'string', 'max:1024', 'distinct:strict'],
            'files.*.media_type' => ['required', 'string', 'max:255'],
            'files.*.size' => ['required', 'integer', 'min:0'],
            'files.*.sha256' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'files.*.type' => ['nullable', 'string', 'max:100'],
            'files.*.metadata' => ['nullable', 'array'],
        ]);

        $artifacts = $this->runs->declareArtifacts($this->findAttempt($attempt), $this->token($request), $validated['files']);

        return $this->respond($this->resource('artifact')::collection($artifacts->load('attempt')));
    }

    public function wait(Request $request, string $attempt): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'array'],
            'resume_at' => ['nullable', 'date', 'after:now'],
            'metadata' => ['nullable', 'array'],
        ]);

        $run = $this->runs->wait(
            $this->findAttempt($attempt),
            $this->token($request),
            $validated['reason'],
            $validated['state'] ?? null,
            isset($validated['resume_at']) ? Carbon::parse($validated['resume_at']) : null,
            $validated['metadata'] ?? null,
        );

        return $this->respond(new ($this->resource('run'))($run));
    }

    private function token(Request $request): string
    {
        $token = (string) $request->header(self::TOKEN_HEADER, '');

        return $token !== '' ? $token : throw new InvalidLeaseToken;
    }
}
