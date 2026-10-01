<?php

use App\Http\Controllers\Chat\ChatAttachmentController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\PresenceController;
use App\Http\Controllers\Chat\PriorityBroadcastController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| chat.use is the module gate; ConversationPolicy / MessagePolicy then require
| the caller to be a participant. No route lets anyone (Admin included) list
| or read conversations they are not part of.
*/

Route::post('presence/heartbeat', [PresenceController::class, 'heartbeat'])->name('presence.heartbeat');

Route::middleware('permission:chat.use')->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->whereNumber('conversation')->name('chat.show');
    Route::get('chat/conversations', [ChatController::class, 'conversations'])->name('chat.conversations.index');
    Route::get('chat/users', [ChatController::class, 'users'])->middleware('throttle:search')->name('chat.users.index');
    Route::post('chat/users/{user}/conversation', [ChatController::class, 'start'])->whereNumber('user')->name('chat.users.start');

    Route::get('chat/conversations/{conversation}/messages', [ChatMessageController::class, 'index'])->whereNumber('conversation')->name('chat.messages.index');
    Route::post('chat/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])->whereNumber('conversation')->middleware('throttle:chat')->name('chat.messages.store');
    Route::post('chat/conversations/{conversation}/read', [ChatMessageController::class, 'read'])->whereNumber('conversation')->name('chat.conversations.read');
    Route::post('chat/conversations/{conversation}/typing', [ChatMessageController::class, 'typing'])->whereNumber('conversation')->name('chat.conversations.typing');
    Route::put('chat/messages/{message}', [ChatMessageController::class, 'update'])->whereNumber('message')->middleware('throttle:chat')->name('chat.messages.update');
    Route::delete('chat/messages/{message}', [ChatMessageController::class, 'destroy'])->whereNumber('message')->name('chat.messages.destroy');

    Route::get('chat/attachments/{attachment}', [ChatAttachmentController::class, 'show'])->whereNumber('attachment')->name('chat.attachments.show');
});

Route::get('priority-broadcasts', [PriorityBroadcastController::class, 'index'])->middleware('permission:priority_broadcast.view_history')->name('priority-broadcasts.index');
Route::post('priority-broadcasts', [PriorityBroadcastController::class, 'store'])->middleware(['permission:priority_broadcast.send', 'throttle:sensitive'])->name('priority-broadcasts.store');
Route::get('priority-broadcasts/{broadcast}', [PriorityBroadcastController::class, 'show'])->whereNumber('broadcast')->name('priority-broadcasts.show');
Route::post('priority-broadcasts/{broadcast}/read', [PriorityBroadcastController::class, 'read'])->whereNumber('broadcast')->name('priority-broadcasts.read');
Route::post('priority-broadcasts/{broadcast}/acknowledge', [PriorityBroadcastController::class, 'acknowledge'])->whereNumber('broadcast')->name('priority-broadcasts.acknowledge');
