<?php

use Illuminate\Support\Facades\Route;
use Whilesmart\Runs\Http\Controllers\AttemptController;
use Whilesmart\Runs\Http\Controllers\RunnerController;

$attempts = config('runs.controllers.attempts', AttemptController::class);

if (config('runs.register_runner_routes', false)) {
    Route::post('claim', [config('runs.controllers.runner', RunnerController::class), 'claim']);
}

Route::post('attempts/{attempt}/heartbeat', [$attempts, 'heartbeat']);
Route::post('attempts/{attempt}/logs', [$attempts, 'logs']);
Route::post('attempts/{attempt}/artifacts', [$attempts, 'artifact']);
Route::post('attempts/{attempt}/complete', [$attempts, 'complete']);
Route::post('attempts/{attempt}/fail', [$attempts, 'fail']);
