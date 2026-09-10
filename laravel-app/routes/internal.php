<?php

use App\Http\Controllers\Internal\ProcessingRunProgressController;
use App\Http\Middleware\AuthenticateProcessingCallback;
use Illuminate\Support\Facades\Route;

Route::post(
    '/processing-runs/{processingRun}/events',
    ProcessingRunProgressController::class,
)->middleware(AuthenticateProcessingCallback::class)
    ->whereNumber('processingRun');
