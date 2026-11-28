<?php

namespace App\Domain\AuthenticationKey\Handlers;

use App\Domain\AuthenticationKey\Aggregates\AuthenticationKeyAggregate;
use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\AuthenticationKey\Services\AuthenticationKeyHasher;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyStatus;
use App\Models\AuthenticationKeyType;
use Illuminate\Support\Str;

/**
 * UC-K001/UC-K002: 認証キーを登録する。生の値は保存せずHMAC-SHA256のハッシュ値のみ保存する。
 * 同一の(key_type, key_hash)で有効な行が他ユーザーに存在する場合は登録を拒否する
 * (AUTHENTICATION_KEY_DUPLICATED)。
 *
 * @implements CommandHandler<IssueAuthenticationKey>
 */
class IssueAuthenticationKeyHandler implements CommandHandler
{
    public function __construct(
        private readonly AuthenticationKeyHasher $hasher,
    ) {}

    public function handle(Command $command): AuthenticationKey
    {
        assert($command instanceof IssueAuthenticationKey);

        if (! in_array($command->keyType, AuthenticationKeyType::values(), true)) {
            throw new DomainRuleException('不正な認証キー種別です。');
        }

        $aggregateUuid = $command->authenticationKeyId ?? (string) Str::uuid();

        // Reactor経由は冪等: そのIDの認証キーが発行済みなら何もしない(他のキーとの重複は下で拒否する)。
        if ($command->viaReactor && AuthenticationKey::query()->whereKey($aggregateUuid)->exists()) {
            return AuthenticationKey::query()->findOrFail($aggregateUuid);
        }

        $keyHash = $command->keyHash ?? ($command->rawKeyValue !== null ? $this->hasher->hash($command->rawKeyValue) : null);
        if ($keyHash === null) {
            throw new DomainRuleException('認証キーの値が指定されていません。');
        }

        $duplicated = AuthenticationKey::query()
            ->where('key_type', $command->keyType)
            ->where('key_hash', $keyHash)
            ->where('status', AuthenticationKeyStatus::ACTIVE)
            ->exists();

        if ($duplicated) {
            throw new DomainRuleException('この認証キーは既に他のユーザーに登録されています。');
        }

        AuthenticationKeyAggregate::retrieve($aggregateUuid)
            ->issue(
                userId: $command->userId,
                keyType: $command->keyType,
                displayName: $command->displayName,
                keyHash: $keyHash,
                validFrom: $command->validFrom,
                validUntil: $command->validUntil,
                metadata: $command->metadata,
                registeredByUserId: $command->registeredByUserId,
                registeredAt: now()->format('Y-m-d H:i:s'),
            )
            ->persist();

        return AuthenticationKey::query()->findOrFail($aggregateUuid);
    }
}
