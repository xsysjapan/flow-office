<?php

namespace App\Http\Middleware;

use App\Services\EventSourcing\ReadModelRebuildRunService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ReadModelの作り直しの実行中(`ReadModelRebuildRunService::isRunning()`)は、全APIを503(`code=maintenance`)にする
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点3)。
 * 通すのは、メンテナンスの解除の検知と進み具合の確認に要る読み取り(GET)だけ。
 */
class BlockDuringReadModelRebuild
{
    public const MESSAGE = 'システムのメンテナンス(データの作り直し)中です。しばらくしてから再度お試しください。';

    /** メンテナンス中も通すGET(APIプレフィックスを除いたパス)。 */
    private const ALLOWED_GET_PATTERNS = [
        '#^maintenance-status$#',
        '#^admin/read-model-rebuilds$#',
        '#^admin/read-model-rebuilds/[^/]+$#',
        '#^auth/me$#',
        '#^access/me$#',
        '#^onboarding/status$#',
    ];

    public function __construct(private readonly ReadModelRebuildRunService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->service->isRunning() && ! $this->isAllowed($request)) {
            return response()->json(['code' => 'maintenance', 'message' => self::MESSAGE], 503);
        }

        return $next($request);
    }

    private function isAllowed(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $path = trim($request->path(), '/');
        $prefix = trim((string) config('app.api_prefix', ''), '/');
        if ($prefix !== '' && str_starts_with($path, $prefix.'/')) {
            $path = substr($path, strlen($prefix) + 1);
        }

        foreach (self::ALLOWED_GET_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
