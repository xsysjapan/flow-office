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
     *
     * 個人API/MCP連携(docs/25-usecases-integrations-mcp.md)のようなability限定トークンでは、
     * たとえ本人がadminロールを持っていても管理者としての抜け道を使わせない
     * (「管理職であっても、個人トークンへ自動的に部下の閲覧権限を付与しない」UC-I002)。
     * ability`*`を持つ通常の人間向けトークンでは今まで通りadmin判定を適用する。
     */
    protected function abortUnlessOwnerOrAdmin(Request $request, string $ownerId, string $message): void
    {
        $isSelf = $ownerId === $request->user()->id;
        $isAdmin = $this->currentTokenHasFullAccess($request) && $request->user()->hasRole(Role::ADMIN);

        abort_if(! $isSelf && ! $isAdmin, 403, $message);
    }

    /**
     * 現在のSanctumトークンがability`*`(通常の人間向けログインセッション)かどうか。
     * トークンを持たない場合(セッション認証等)はtrueとして扱う。
     */
    protected function currentTokenHasFullAccess(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token === null || ! method_exists($token, 'can') || $token->can('*');
    }

    /**
     * クエリパラメータ等で指定された対象社員(未指定なら自分自身)を、本人またはadminの
     * 場合のみ解決する(AttendanceController::week/month、AttendancePunchController::index/store
     * で共通利用)。
     */
    protected function resolveTargetUserId(Request $request, ?string $requestedUserId, string $message): string
    {
        $userId = $requestedUserId ?? $request->user()->id;

        $this->abortUnlessOwnerOrAdmin($request, $userId, $message);

        return $userId;
    }
}
