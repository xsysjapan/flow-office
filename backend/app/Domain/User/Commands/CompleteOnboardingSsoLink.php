<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000)のSSOモード: StartOnboardingSsoで
 * 保存した設定を使って実際にEntra IDへログインした結果(Socialiteが返した認証済みの
 * ユーザーID・メール・表示名)をそのまま使って管理者ユーザーを作成する。メールアドレスの
 * 事前入力や文字列一致には一切依存しない。
 */
class CompleteOnboardingSsoLink implements Command
{
    public function __construct(
        public readonly string $entraUserId,
        public readonly string $name,
        public readonly ?string $email,
    ) {}
}
