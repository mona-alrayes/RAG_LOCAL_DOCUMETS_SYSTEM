<?php

use App\Http\Controllers\ConversationAnswerStreamController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentPollingController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/workspace', WorkspaceController::class)->name('workspace');

    Route::view('/settings/account', 'settings.account')->name('settings.account');

    Route::get('/conversations', [ConversationController::class, 'index'])
        ->name('conversations.index');

    Route::post('/conversations', [ConversationController::class, 'store'])
        ->name('conversations.store');

    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
        ->name('conversations.show');

    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])
        ->name('conversations.update');

    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])
        ->name('conversations.destroy');

    Route::get(
        '/conversations/{conversation}/messages/{message}/stream',
        ConversationAnswerStreamController::class,
    )->name('conversations.answers.stream');

    Route::put('/conversations/{conversation}/documents', [ConversationController::class, 'updateDocuments'])
        ->name('conversations.documents.update');

    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('documents.index');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])
        ->name('documents.show');

    Route::get('/documents/{document}/poll', DocumentPollingController::class)
        ->name('documents.poll');

    Route::post('/documents', [DocumentController::class, 'store'])
        ->name('documents.store');

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');

    Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])
        ->name('documents.preview');

    Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
        ->name('documents.reprocess');

    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])
        ->name('documents.destroy');
});
