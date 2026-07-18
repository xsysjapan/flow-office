<?php

namespace App\Domain\AuthenticationKey\Handlers;

use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyIssued;
use App\Domain\AuthenticationKey\Services\AuthenticationKeyHasher;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyStatus;
use App\Models\AuthenticationKeyType;

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
        private readonly EventStore $eventStore,
        private readonly AuthenticationKeyHasher $hasher,
    ) {}

    public function handle(Command $command): AuthenticationKey
    {
        assert($command instanceof IssueAuthenticationKey);

        if (! in_array($command->keyType, AuthenticationKeyType::values(), true)) {
            throw new DomainRuleException('不正な認証キー種別です。');
        }

        $keyHash = $this->hasher->hash($command->rawKeyValue);

        $duplicated = AuthenticationKey::query()
            ->where('key_type', $command->keyType)
            ->where('key_hash', $keyHash)
            ->where('status', AuthenticationKeyStatus::ACTIVE)
            ->exists();

        if ($duplicated) {
            throw new DomainRuleException('この認証キーは既に他のユーザーに登録されています。');
        }

        $key = AuthenticationKey::query()->create([
            'user_id' => $command->userId,
            'key_type' => $command->keyType,
            'display_name' => $command->displayName,
            'key_hash' => $keyHash,
            'status' => AuthenticationKeyStatus::ACTIVE,
            'valid_from' => $command->validFrom,
            'valid_until' => $command->validUntil,
            'metadata_json' => $command->metadata,
            'registered_by_user_id' => $command->registeredByUserId,
            'registered_at' => now(),
        ]);

        $this->eventStore->append(
            aggregateType: 'authentication_key',
            aggregateId: (string) $key->id,
            event: new AuthenticationKeyIssued(
                authenticationKeyId: $key->id,
                userId: $command->userId,
                keyType: $command->keyType,
                displayName: $command->displayName,
                registeredByUserId: $command->registeredByUserId,
            ),
        );

        return $key;
    }
}
