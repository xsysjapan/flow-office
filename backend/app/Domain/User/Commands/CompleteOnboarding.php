<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 初回オンボーディング: Microsoft 365連携設定の登録と最初の管理者ユーザー作成を同時に行う
 * (docs/06-usecases-auth.md)。認証機構(Entra ID SSO)自体がまだ設定されていない状態でも
 * 実行できるよう、未認証で呼び出せるAPIから発行される。
 */
class CompleteOnboarding implements Command
{
    public function __construct(
        public readonly string $adminName,
        public readonly string $adminEmail,
        public readonly string $m365TenantId,
        public readonly string $m365ClientId,
        public readonly string $m365ClientSecret,
        public readonly string $m365RedirectUri,
        public readonly bool $m365MockEnabled,
    ) {}
}
