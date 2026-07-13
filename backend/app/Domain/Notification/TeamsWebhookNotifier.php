<?php

namespace App\Domain\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Teams Webhookへ通知を送る。webhook_url未設定時はログ出力のみに留める
 * (開発環境やWebhook未設定のテナントでも通知処理自体は失敗させない)。
 */
class TeamsWebhookNotifier implements Notifier
{
    public function notify(string $title, string $summary, ?string $detailUrl): bool
    {
        $webhookUrl = config('services.teams.webhook_url');

        if (! $webhookUrl) {
            Log::info('[Teams通知(webhook未設定のためログのみ)] '.$title.' - '.$summary.($detailUrl ? " ({$detailUrl})" : ''));

            return true;
        }

        $response = Http::post($webhookUrl, [
            'title' => $title,
            'text' => $detailUrl ? "{$summary}\n\n{$detailUrl}" : $summary,
        ]);

        if ($response->failed()) {
            Log::warning('Teams通知の送信に失敗しました: '.$response->status().' '.$response->body());

            return false;
        }

        Log::info("Teams通知を送信しました: {$title}");

        return true;
    }
}
