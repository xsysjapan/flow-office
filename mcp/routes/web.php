<?php

use App\Http\Controllers\Auth\LinkBackendTokenController;
use App\Http\Controllers\OAuth\AuthorizeController;
use Illuminate\Support\Facades\Route;

// 注意: 文字通りのルート'/'は登録しない。本番(XSERVER)のように/flow-office/mcpの
// ようなURLサブパスにマウントする(basePathが空でなくなる)構成でroute:cacheを使うと、
// コンパイル済みルートマッチャがルート'/'のGETを誤って405(許可メソッドにGETが
// 含まれない)にしてしまう既知の問題がある(クロージャ・Route::redirect・通常の
// コントローラアクションいずれでも再現する)。トップページの案内は/linkへ直接
// 誘導すれば十分なため、あえて'/'ルート自体を持たない。

// OAuth2認可エンドポイント(ブラウザでの人間の同意画面、docs/25 UC-I001手順6と同じ体裁)。
Route::get('/oauth/authorize', [AuthorizeController::class, 'show'])->name('authorize.show');
Route::post('/oauth/authorize', [AuthorizeController::class, 'approve'])->name('authorize.approve');

// backend/の個人連携Sanctumトークンをmcp/へ紐付ける初回セットアップ画面。
Route::get('/link', [LinkBackendTokenController::class, 'show'])->name('link.show');
Route::post('/link', [LinkBackendTokenController::class, 'store'])->name('link.store');
