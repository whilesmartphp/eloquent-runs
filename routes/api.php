<?php

use Illuminate\Support\Facades\Route;
use Whilesmart\Runs\Http\Controllers\RunController;

$runs = config('runs.controllers.runs', RunController::class);

Route::get('runs', [$runs, 'index']);
Route::post('runs', [$runs, 'store']);
Route::get('runs/{run}', [$runs, 'show']);
Route::post('runs/{run}/cancel', [$runs, 'cancel']);
Route::get('runs/{run}/attempts', [$runs, 'attempts']);
Route::get('runs/{run}/artifacts', [$runs, 'artifacts']);
Route::get('runs/{run}/artifacts/{artifact}', [$runs, 'download']);
Route::get('runs/{run}/logs', [$runs, 'logs']);
