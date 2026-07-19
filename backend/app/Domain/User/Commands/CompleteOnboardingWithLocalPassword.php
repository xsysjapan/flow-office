<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000)のローカルパスワードモード:
 * Microsoft 365 SSOを設定しない場合、その場でパスワードログイン可能な管理者ユーザーを作成する。
 */
class CompleteOnboardingWithLocalPassword implements Command
{
    public function __construct(
        public readonly string $adminName,
        public readonly string $adminEmail,
        public readonly string $adminPassword,
    ) {}
}
