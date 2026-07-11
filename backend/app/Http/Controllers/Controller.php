<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
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
    /**
     * 対象データの所有者本人、またはadminのみ操作できることを保証する
     * (自分以外の社員の勤怠・打刻を参照・記録・訂正・削除できるのはadminのみ)。
     */
    protected function abortUnlessOwnerOrAdmin(Request $request, int $ownerId, string $message): void
    {
        abort_if(
            $ownerId !== $request->user()->id && ! $request->user()->hasRole(Role::ADMIN),
            403,
            $message,
        );
    }
}
