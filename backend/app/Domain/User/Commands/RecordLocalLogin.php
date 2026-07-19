<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * ローカルパスワードログイン(SSOを設定しなかった場合の`POST /auth/local-login`)。
 * パスワード検証自体はコントローラ側で行い(既存ユーザーを特定するため)、このコマンドは
 * 検証成功後にログイン日時を記録するためだけに使う。
 */
class RecordLocalLogin implements Command
{
    public function __construct(public readonly int $userId) {}
}
