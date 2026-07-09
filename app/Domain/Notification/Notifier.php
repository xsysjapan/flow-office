<?php

namespace App\Domain\Notification;

/**
 * UC-N001: Teams通知を送る。Teamsは通知専用(チャット・掲示板は作らない)。
 */
interface Notifier
{
    public function notify(string $title, string $summary, ?string $detailUrl): void;
}
