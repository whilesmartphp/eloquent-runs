<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FingerprintedStoreRequest;
use Tests\TestCase;
use Whilesmart\Runs\Models\Run;

class ConcurrentRequestsTest extends TestCase
{
    private function race(callable $request, string $event): array
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concurrent requests require MySQL and pcntl.');
        }

        $directory = sys_get_temp_dir().'/runs-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        DB::purge();
        $children = [];
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork a request process.');
                }
                if ($pid === 0) {
                    pcntl_alarm(15);
                    try {
                        $waiting = false;
                        Run::{$event}(function () use ($directory, $index, &$waiting) {
                            if ($waiting) {
                                return;
                            }
                            $waiting = true;
                            touch($directory.'/ready-'.$index);
                            $deadline = microtime(true) + 10;
                            while (! is_file($directory.'/ready-'.(1 - $index))) {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('The other request did not reach the barrier.');
                                }
                                usleep(1000);
                            }
                        });
                        $response = $request($index);
                        file_put_contents($directory.'/result-'.$index, json_encode([
                            'status' => $response->getStatusCode(),
                            'body' => $response->getContent(),
                        ], JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 20;
            foreach ($children as $pid) {
                do {
                    $finished = pcntl_waitpid($pid, $status, WNOHANG);
                    if (microtime(true) > $deadline) {
                        $this->fail('Concurrent HTTP requests did not finish.');
                    }
                    usleep(1000);
                } while ($finished === 0);
                $this->assertTrue(pcntl_wifexited($status), 'A request process terminated without returning a response.');
                $this->assertSame(0, pcntl_wexitstatus($status), implode('\n', array_map('file_get_contents', glob($directory.'/result-*'))));
            }

            return array_map(fn ($index) => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function competing_http_claims_create_one_attempt(): void
    {
        $this->createRun()->assertCreated();
        $responses = $this->race(fn () => $this->claim(), 'retrieved');
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 204], $statuses);
        $this->assertDatabaseCount('work_run_attempts', 1);
        $this->assertDatabaseHas('work_runs', ['attempt_count' => 1, 'status' => 'running']);
    }

    #[Test]
    public function simultaneous_matching_requests_replay_the_winner(): void
    {
        $responses = $this->race(fn () => $this->createRun(['idempotency_key' => 'concurrent']), 'creating');
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $first = json_decode($responses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($responses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($first['data']['run']['id'], $second['data']['run']['id']);
        $this->assertDatabaseCount('work_runs', 1);
    }

    #[Test]
    public function competing_payloads_cannot_share_a_fingerprinted_key(): void
    {
        config(['runs.requests.store_run' => FingerprintedStoreRequest::class]);
        $responses = $this->race(fn ($index) => $this->createRun([
            'idempotency_key' => 'concurrent', 'type' => 'add', 'input' => ['numbers' => [$index]],
        ]), 'creating');
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('work_runs', 1);
    }
}
