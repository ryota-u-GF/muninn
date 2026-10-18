<?php

use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ProjectAccessController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TvController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
| 認証
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('demo-login', [LoginController::class, 'demo'])->middleware('throttle:login')->name('demo-login');
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->middleware('throttle:login')->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->middleware('throttle:login')->name('google.callback');
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    /*
    | 画面（Inertia）。データは同じ認可を通る /api から取得する。
    */
    Route::inertia('/', 'board')->name('board');
    Route::inertia('today', 'today')->name('today');
    Route::inertia('my-tasks', 'my-tasks')->name('my-tasks');
    Route::inertia('people', 'people')->name('people');
    Route::inertia('schedule', 'schedule')->name('schedule');
    Route::inertia('documents', 'documents', ['kind' => 'document'])->name('documents');
    Route::inertia('minutes', 'documents', ['kind' => 'minutes'])->name('minutes');
    Route::inertia('history', 'history')->name('history');
    Route::inertia('projects', 'projects')->middleware('can:manage-projects')->name('projects');
    Route::inertia('imports', 'imports')->middleware('can:use-imports')->name('imports');
    Route::inertia('admin/access', 'admin/access')->middleware('can:admin')->name('admin.access');
    Route::inertia('tv', 'tv')->middleware('can:view-tv')->name('tv.page');

    /*
    | JSON API（セッション Cookie + CSRF）
    */
    Route::prefix('api')->middleware('throttle:api')->group(function () {
        Route::get('tv', [TvController::class, 'index'])->middleware('can:view-tv')->name('tv.data');

        Route::get('members', [MemberController::class, 'index']);

        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->whereNumber('project');

        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('tasks', [TaskController::class, 'store']);
        Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
        Route::patch('tasks/{task}', [TaskController::class, 'update'])->whereNumber('task');
        Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task');

        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::post('documents/parse-file', [DocumentController::class, 'parseFile'])->middleware('throttle:uploads');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document');
        Route::patch('documents/{document}', [DocumentController::class, 'update'])->whereNumber('document');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document');

        Route::get('history', [HistoryController::class, 'index']);
        Route::get('{entity}/{id}/revisions', [HistoryController::class, 'entity'])->whereIn('entity', ['tasks', 'documents'])->whereNumber('id');
        Route::get('{entity}/{id}/revisions/{version}', [HistoryController::class, 'show'])->whereIn('entity', ['tasks', 'documents'])->whereNumber(['id', 'version']);
        Route::post('{entity}/{id}/restore', [HistoryController::class, 'restore'])->whereIn('entity', ['tasks', 'documents'])->whereNumber('id');

        Route::middleware('can:use-imports')->group(function () {
            Route::get('imports', [ImportController::class, 'index']);
            Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:imports');
            Route::post('imports/confirm', [ImportController::class, 'confirm'])->middleware('throttle:imports');
            Route::patch('imports/{candidate}', [ImportController::class, 'update'])->whereNumber('candidate');
            Route::post('imports/{candidate}/reject', [ImportController::class, 'reject'])->whereNumber('candidate');
        });

        Route::middleware('can:admin')->group(function () {
            Route::get('project-access', [ProjectAccessController::class, 'index']);
            Route::put('project-access', [ProjectAccessController::class, 'update']);
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user');
        });
    });
});
