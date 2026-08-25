<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\GameRoomController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoomInviteController;
use App\Http\Controllers\Api\RoomPlayerController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SongSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public: rooms are joined by code/nickname, no host login required.
Route::get('/rooms/{code}', [GameRoomController::class, 'show']);
Route::post('/rooms/{code}/join', [RoomPlayerController::class, 'store']);

Route::middleware('auth:player')->group(function () {
    Route::post('/rounds/{round}/guess', [RoundController::class, 'guess']);
    Route::get('/songs/search', [SongSearchController::class, 'search']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/ping', function (Request $request) {
        return response()->json([
            'pong' => true,
            'authenticated' => $request->user() !== null,
        ]);
    });

    Route::post('/rooms', [GameRoomController::class, 'store']);
    Route::patch('/rooms/{code}', [GameRoomController::class, 'update']);
    Route::post('/rooms/{code}/start', [GameRoomController::class, 'start']);
    Route::post('/rooms/{code}/redo', [GameRoomController::class, 'redo']);

    Route::patch('/profile', [ProfileController::class, 'update']);

    Route::get('/friends', [FriendController::class, 'index']);
    Route::post('/friends', [FriendController::class, 'store']);
    Route::post('/friends/{friendship}/accept', [FriendController::class, 'accept']);
    Route::delete('/friends/{friendship}', [FriendController::class, 'destroy']);

    Route::post('/rooms/{code}/invite', [RoomInviteController::class, 'store']);
});
