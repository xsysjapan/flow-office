<?php

namespace App\Mcp\Support;

use Throwable;

/**
 * MCPツールの戻り値を統一する(mcp-server/src/toolResult.tsのPHP版)。
 * docs/25-usecases-integrations-mcp.md「MCPサーバーが担当する処理」の「エラーの説明可能な
 * 形式への変換」に対応し、backend APIのエラー(422/403/409等)をClaudeが読める説明文に
 * 変換する。勤怠ルールの妥当性判定そのものはbackend側の責務であり、ここでは変換のみ行う。
 */
class ToolResult
{
    public static function success(mixed $data): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
            ],
        ];
    }

    public static function error(string $message): array
    {
        return [
            'isError' => true,
            'content' => [
                ['type' => 'text', 'text' => $message],
            ],
        ];
    }

    public static function run(callable $fn): array
    {
        try {
            return self::success($fn());
        } catch (BackendApiException $e) {
            return self::error("flow-office APIエラー(HTTP {$e->status}): {$e->getMessage()}");
        } catch (Throwable $e) {
            return self::error('予期しないエラーが発生しました: '.$e->getMessage());
        }
    }
}
