<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::get('/dashboard', [HomeController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');
Route::get('/workspace/profiles/live', [WorkspaceController::class, 'live'])->middleware('auth')->name('workspace.live');
Route::get('/workspace/reports/export', [WorkspaceController::class, 'export'])->middleware('auth')->name('workspace.export');
Route::post('/workspace/{section}/{action}', [WorkspaceController::class, 'action'])->middleware('auth')->name('workspace.action');
Route::get('/workspace/{section}', [WorkspaceController::class, 'show'])->middleware('auth')->where('section', '[a-z][a-z0-9-]*')->name('workspace.section');

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [HomeController::class, 'admin'])->name('dashboard');
    Route::get('/profiles/live', [WorkspaceController::class, 'live'])->name('live');
    Route::post('/{section}/{action}', [WorkspaceController::class, 'action'])->name('action');
    Route::get('/{section}', [WorkspaceController::class, 'show'])->where('section', '[a-z][a-z0-9-]*')->name('section');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
