<?php

use App\Http\Controllers\Api\InboundCandidateController;
use Illuminate\Support\Facades\Route;

/*
| 外部システムからの候補の受け取り（セッションではなく共有トークンで認証）
*/
Route::prefix('api/inbound')->middleware(['api', 'inbound', 'throttle:inbound'])->group(function () {
    Route::post('candidates', [InboundCandidateController::class, 'store'])->name('inbound.candidates');
});
