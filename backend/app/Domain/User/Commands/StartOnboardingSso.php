<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000)のSSOモード: Microsoft 365
 * 連携設定を保存し、実際のEntra IDログイン(CompleteOnboardingSsoLink)を待つ状態にする。
 * この時点ではユーザーは作成しない(誰が管理者になるかはOIDCの認証結果で決まるため)。
 */
class StartOnboardingSso implements Command
{
    public function __construct(
        public readonly string $m365TenantId,
        public readonly string $m365ClientId,
        public readonly string $m365ClientSecret,
        public readonly bool $m365MockEnabled,
    ) {}
}
