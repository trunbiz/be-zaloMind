<?php

use App\Http\Controllers\AgentController;
use App\Http\Middleware\AgentAuthenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/platform.php';

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1/agent')->middleware(AgentAuthenticate::class)->group(function () {
    Route::get('config', [AgentController::class, 'config']);
    Route::post('heartbeat', [AgentController::class, 'heartbeat']);
    Route::get('profiles', [AgentController::class, 'profiles']);
    Route::post('commands/claim', [AgentController::class, 'claim']);
    Route::post('commands/{id}/lease', [AgentController::class, 'lease']);
    Route::post('commands/{id}/progress', [AgentController::class, 'progress']);
    Route::post('commands/{id}/result', [AgentController::class, 'result']);
    Route::post('events', [AgentController::class, 'events']);
    Route::post('profiles/{id}/snapshots', [AgentController::class, 'createSnapshot']);
    Route::put('profiles/{id}/snapshots/{syncId}/pages/{page}', [AgentController::class, 'snapshotPage']);
    Route::post('profiles/{id}/snapshots/{syncId}/complete', [AgentController::class, 'completeSnapshot']);
    Route::get('attachments/{id}/download', [AgentController::class, 'download']);
});
