<?php

use App\Http\Controllers\Auth\LinkBackendTokenController;
use App\Http\Controllers\OAuth\AuthorizeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('link.show'));

// OAuth2認可エンドポイント(ブラウザでの人間の同意画面、docs/25 UC-I001手順6と同じ体裁)。
Route::get('/oauth/authorize', [AuthorizeController::class, 'show'])->name('authorize.show');
Route::post('/oauth/authorize', [AuthorizeController::class, 'approve'])->name('authorize.approve');

// backend/の個人連携Sanctumトークンをmcp/へ紐付ける初回セットアップ画面。
Route::get('/link', [LinkBackendTokenController::class, 'show'])->name('link.show');
Route::post('/link', [LinkBackendTokenController::class, 'store'])->name('link.store');
