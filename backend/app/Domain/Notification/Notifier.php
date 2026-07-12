<?php

namespace App\Domain\Notification;

/**
 * UC-N001: Teams通知を送る。Teamsは通知専用(チャット・掲示板は作らない)。
 */
interface Notifier
{
    /**
     * @return bool 送信に成功した場合true(Webhook未設定でログのみの場合もtrue)。
     *              Webhook呼び出し自体が失敗した場合はfalse。
     */
    public function notify(string $title, string $summary, ?string $detailUrl): bool;
}
