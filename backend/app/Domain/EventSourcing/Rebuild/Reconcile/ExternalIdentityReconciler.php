<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use App\Domain\UserManagement\Support\UserManagementStreamId;

/**
 * external_identities の照合(論点19 決定1)。キーは (provider, external_subject_id)、集約UUIDは
 * UserManagementStreamId::for('user-identity', userId)。
 *
 * イベントによる最終状態(利用者と status): external_identity.linked(active)・external_identity.unlinked(unlinked)、利用者の
 * 作成・連携のイベント(user.onboarded_as_admin・created_from_sso_login・synced_from_ms365・sso_account_linked の entraUserId =
 * MICROSOFT_ENTRA)、external_hr.import_applied の rows(EXTERNAL_HR)を、id順に畳み込んだもの。
 * - 行があり、そのキーのイベントが1件も無い: external_identity.linked を導出する。集約にイベントが無ければ、利用者の作成イベント
 *   (既存または導出)の直後(無ければ列の先頭)。イベントがあれば最初のイベントの直前(ただし利用者の作成より前には置かない)。
 * - 行があり、最終状態が違う(利用者・status): 最後のイベントの直後(集約にイベントが無ければ利用者の作成イベントの直後)に導出する。
 * - 行の status が active でない場合は、unlinked の操作者(必須)を決められないため、扱えない並びとして止める。
 * 操作者は null(行に作成者の列が無い)。
 */
final class ExternalIdentityReconciler
{
    public const TABLE = 'external_identities';

    private const USER_ENTRA_CLASSES = [
        'user.onboarded_as_admin',
        'user.created_from_sso_login',
        'user.synced_from_ms365',
        'user.sso_account_linked',
    ];

    public function reconcile(ReconcileContext $ctx): void
    {
        /** @var array<string, array{user_id: string, status: string}> $final */
        $final = [];
        foreach ($ctx->rows() as $event) {
            $class = $event->eventClass;
            if ($class === 'external_identity.linked') {
                $final[$this->key((string) $event->get('provider'), (string) $event->get('externalSubjectId'))] = ['user_id' => (string) $event->get('userId'), 'status' => 'active'];
            } elseif ($class === 'external_identity.unlinked') {
                $key = $this->key((string) $event->get('provider'), (string) $event->get('externalSubjectId'));
                $final[$key] = ['user_id' => (string) $event->get('userId'), 'status' => 'unlinked'];
            } elseif (in_array($class, self::USER_ENTRA_CLASSES, true) && $event->get('entraUserId') !== null && $event->aggregateUuid !== null) {
                $final[$this->key('MICROSOFT_ENTRA', (string) $event->get('entraUserId'))] = ['user_id' => $event->aggregateUuid, 'status' => 'active'];
            } elseif ($class === 'external_hr.import_applied') {
                foreach ((array) $event->get('rows', []) as $importRow) {
                    if (isset($importRow['external_subject_id'], $importRow['user_id'])) {
                        $final[$this->key('EXTERNAL_HR', (string) $importRow['external_subject_id'])] = ['user_id' => (string) $importRow['user_id'], 'status' => 'active'];
                    }
                }
            }
        }

        foreach ($ctx->current->externalIdentities as $row) {
            $row = Norm::row($row);
            $key = $this->key((string) $row['provider'], (string) $row['external_subject_id']);
            $ctx->guard("external_identity {$key}", fn () => $this->one($ctx, $row, $key, $final[$key] ?? null));
        }
    }

    private function key(string $provider, string $subject): string
    {
        return $provider.'|'.$subject;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{user_id: string, status: string}|null  $final
     */
    private function one(ReconcileContext $ctx, array $row, string $key, ?array $final): void
    {
        $userId = (string) $row['user_id'];
        $status = (string) $row['status'];
        if ($final !== null && $final['user_id'] === $userId && $final['status'] === $status) {
            return;
        }
        if ($status !== 'active') {
            throw new UnsupportedEventSequence("external_identity {$key}", "status={$status} の行は、external_identity.unlinked の操作者を決められない");
        }

        $aggregate = UserManagementStreamId::for('user-identity', $userId);
        $createdAt = Norm::str($row['created_at'] ?? null);
        $dep = $ctx->ref('user:'.$userId);
        if ($final !== null) {
            $ctx->mismatch(self::TABLE, $key, $final['user_id'] !== $userId ? '利用者がイベントと違う' : 'イベントでは連携解除済みだが行がある');
            $slot = $ctx->afterLast($aggregate) ?? ($dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt));
        } else {
            $slot = $ctx->beforeFirst($aggregate, $createdAt);
            $slot = $slot !== null
                ? $ctx->notBefore($slot, $dep)
                : ($dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt));
        }

        $ctx->emit(self::TABLE, $slot, 'external_identity.linked', $aggregate, [
            'userId' => $userId,
            'provider' => (string) $row['provider'],
            'externalTenantId' => Norm::str($row['external_tenant_id'] ?? null),
            'externalSubjectId' => (string) $row['external_subject_id'],
            'externalCode' => Norm::str($row['external_code'] ?? null),
            'email' => Norm::str($row['email'] ?? null),
            'actorUserId' => null,
        ]);
    }
}
