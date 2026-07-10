<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'flow-office API',
    description: '勤怠・申請・バックオフィス処理システムのバックエンドAPI。認証はLaravel Sanctumの'
        .'Bearerトークン方式(`Authorization: Bearer <token>`)。'
)]
#[OA\Server(url: '/api', description: 'このドキュメントを配信しているサーバー')]
abstract class Controller
{
    //
}
