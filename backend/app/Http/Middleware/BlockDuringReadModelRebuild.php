<?php

namespace App\Http\Middleware;

use App\Services\EventSourcing\CorrectionPendingService;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ReadModelの作り直しの実行中(`ReadModelRebuildRunService::isRunning()`)は、全APIを503(`code=maintenance`)にする
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点3)。
 * 通すのは、メンテナンスの解除の検知と進み具合の確認に要る読み取り(GET)だけ。
 *
 * 加えて、補正待ち(`CorrectionPendingService::isPending()`。旧形式のイベントが残っている)の間も、API を 503 にする
 * (`code=correction_pending`。docs/changesets/20261124-correction-pending-maintenance/spec.md 論点1)。管理者が補正を実行できるよう、
 * 作り直しの実行中に通すGETに加えて、`auth/*`(ログイン・認証。メソッド問わず)と運用コマンドの実行の依頼(POST `admin/commands/{command}/runs`)も通す。
 * `code`を`maintenance`と分けるのは、フロントの全画面のメンテナンス表示(`maintenance-status`が false になると再読込する)が、
 * 補正の操作そのものを妨げないようにするため。
 */
class BlockDuringReadModelRebuild
{
    public const MESSAGE = 'システムのメンテナンス(データの作り直し)中です。しばらくしてから再度お試しください。';

    /** メンテナンス中も通すGET(APIプレフィックスを除いたパス)。 */
    private const ALLOWED_GET_PATTERNS = [
        '#^maintenance-status$#',
        '#^auth/me$#',
        '#^access/me$#',
        '#^onboarding/status$#',
        '#^admin/commands$#',
        '#^admin/command-runs$#',
    ];

    /** 補正待ちの間も通す(メソッド問わず)パス。ログインと認証。 */
    private const CORRECTION_PENDING_ALLOWED_PATTERNS = [
        '#^auth/#',
    ];

    /** 補正待ちの間も通すPOSTのパス。運用コマンドの実行の依頼。 */
    private const CORRECTION_PENDING_ALLOWED_POST_PATTERNS = [
        '#^admin/commands/.+/runs$#',
    ];

    public function __construct(
        private readonly ReadModelRebuildRunService $service,
        private readonly CorrectionPendingService $correctionPending,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->service->isRunning()) {
            if (! $this->isAllowedGet($request)) {
                return response()->json(['code' => 'maintenance', 'message' => self::MESSAGE], 503);
            }
        } elseif ($this->correctionPending->isPending() && ! $this->isAllowedWhileCorrectionPending($request)) {
            return response()->json(['code' => 'correction_pending', 'message' => CorrectionPendingService::MESSAGE], 503);
        }

        return $next($request);
    }

    private function isAllowedWhileCorrectionPending(Request $request): bool
    {
        if ($this->isAllowedGet($request)) {
            return true;
        }

        $path = $this->path($request);
        foreach (self::CORRECTION_PENDING_ALLOWED_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        if ($request->isMethod('POST')) {
            foreach (self::CORRECTION_PENDING_ALLOWED_POST_PATTERNS as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** APIプレフィックスを除いたパス。 */
    private function path(Request $request): string
    {
        $path = trim($request->path(), '/');
        $prefix = trim((string) config('app.api_prefix', ''), '/');
        if ($prefix !== '' && str_starts_with($path, $prefix.'/')) {
            $path = substr($path, strlen($prefix) + 1);
        }

        return $path;
    }

    private function isAllowedGet(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $path = $this->path($request);

        foreach (self::ALLOWED_GET_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
