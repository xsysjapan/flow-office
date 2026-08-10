<?php

namespace App\Http\Controllers\Api;

use App\Domain\UserManagement\Ms365ConfigResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Playwright から期限到来済みの所属変更バッチを実行する開発専用エンドポイント。 */
class DevApplyMembershipChangesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! Ms365ConfigResolver::mockEnabled()) {
            throw new NotFoundHttpException;
        }

        $exitCode = Artisan::call('user-management:apply-membership-changes');

        return response()->json([
            'status' => $exitCode === 0 ? 'ok' : 'failed',
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ], $exitCode === 0 ? 200 : 500);
    }
}
