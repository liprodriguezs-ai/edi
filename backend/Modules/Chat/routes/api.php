<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatChannelController;
use Modules\Chat\Http\Controllers\ChatChannelMemberController;
use Modules\Chat\Http\Controllers\ChatChannelTypeController;
use Modules\Chat\Http\Controllers\ChatMessageController;
use Modules\Chat\Http\Controllers\ChatMessageReportController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function (): void {
    $customMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];

    // Catalog resource
    Orion::resource('chat_channel_type', ChatChannelTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Literal route registered BEFORE the resource so it is never shadowed.
    Route::middleware($customMiddleware)->group(function (): void {
        Route::post('chat_channel/direct', [ChatChannelController::class, 'direct']);
    });

    // Main entity resources. Reads/searches go through the FULL auth chain
    // ($customMiddleware): a refresh token or a deactivated account
    // (auth_active=false) must not be able to read, search or post in the chat.
    Orion::resource('chat_channel', ChatChannelController::class)
        ->withSoftDeletes()
        ->middleware($customMiddleware);

    Orion::resource('chat_channel_member', ChatChannelMemberController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Messages are NEVER updated or deleted via REST (§13.4.4): the author edits
    // through the /edit action (ownership + window), moderation hides/unhides.
    Orion::resource('chat_message', ChatMessageController::class)
        ->only(['index', 'search', 'show', 'store'])
        ->middleware($customMiddleware);

    // Moderation review of reports — read-only resource + dismiss action below.
    Orion::resource('chat_message_report', ChatMessageReportController::class)
        ->only(['index', 'search', 'show'])
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Custom actions
    Route::middleware($customMiddleware)->group(function (): void {
        Route::post('chat-message/{id}/hide', [ChatMessageController::class, 'hide']);
        Route::post('chat-message/{id}/unhide', [ChatMessageController::class, 'unhide']);
        Route::post('chat-message/{id}/edit', [ChatMessageController::class, 'edit']);
        Route::post('chat-message/{id}/react', [ChatMessageController::class, 'react']);
        Route::delete('chat-message/{id}/react', [ChatMessageController::class, 'unreact']);
        Route::post('chat-message/{id}/report', [ChatMessageController::class, 'report']);
        Route::post('chat-message-report/{id}/dismiss', [ChatMessageReportController::class, 'dismiss']);
        Route::post('chat-channel/{id}/join', [ChatChannelController::class, 'join']);
        Route::post('chat-channel/{id}/copy-config', [ChatChannelController::class, 'copyConfig']);
    });
});
