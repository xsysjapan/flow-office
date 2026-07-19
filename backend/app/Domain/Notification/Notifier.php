<?php

namespace App\Domain\Notification;

use App\Models\User;

/**
 * UC-N001: メール通知を送る。
 */
interface Notifier
{
    /**
     * @return bool 送信に成功した場合true(メール未設定でログのみの場合もtrue)。
     *              送信自体が失敗した場合はfalse。
     */
    public function notify(User $recipient, string $title, string $summary, ?string $detailUrl): bool;
}
