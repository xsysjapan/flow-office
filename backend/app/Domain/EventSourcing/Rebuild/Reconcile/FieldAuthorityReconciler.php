<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use Ramsey\Uuid\Uuid;

/**
 * field_authorities の照合(論点19 決定1)。キーは field_key、集約UUIDは全項目共通の uuid5(URL, 'field-authority-catalog')。
 *
 * イベントによる最終状態: field_key ごとの最後の user.field_authority_changed の (authorityType, provider)。
 * - その field_key のイベントが1件も無い: user.field_authority_changed を導出する。集約にイベントが無ければ列の先頭、あれば
 *   集約の最初のイベントの直前。発生日時は、列の先頭なら行の created_at、それ以外は置き場所の前のイベントの値。
 * - 最終状態が行と違う: 集約の最後のイベントの直後に導出する。
 * 操作者は null(行に作成者の列が無い)。
 */
final class FieldAuthorityReconciler
{
    public const TABLE = 'field_authorities';

    public function reconcile(ReconcileContext $ctx): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'field-authority-catalog')->toString();
        $final = [];
        foreach ($ctx->rowsOfClasses($aggregate, ['user.field_authority_changed']) as $event) {
            $final[(string) $event->get('fieldKey')] = [Norm::str($event->get('authorityType')), Norm::str($event->get('provider'))];
        }

        $rows = array_map(fn ($row) => Norm::row($row), $ctx->current->fieldAuthorities);
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['field_key'], (string) $b['field_key']));

        foreach ($rows as $row) {
            $fieldKey = (string) $row['field_key'];
            $want = [Norm::str($row['authority_type']), Norm::str($row['provider'] ?? null)];
            if (($final[$fieldKey] ?? null) === $want) {
                continue;
            }

            $ctx->guard("field_authority {$fieldKey}", function () use ($ctx, $row, $fieldKey, $want, $aggregate, $final): void {
                $createdAt = null;
                if (isset($final[$fieldKey])) {
                    $ctx->mismatch(self::TABLE, $fieldKey, '最終状態が違う: '.ReconcileContext::describe(
                        ['authority_type' => $final[$fieldKey][0], 'provider' => $final[$fieldKey][1]],
                        ['authority_type' => $want[0], 'provider' => $want[1]],
                        ['authority_type', 'provider'],
                    ), ['user.field_authority_changed']);
                    $slot = $ctx->afterLast($aggregate);
                } else {
                    $createdAt = Norm::str($row['created_at'] ?? null);
                    $slot = $ctx->beforeFirst($aggregate, $createdAt) ?? $ctx->head($createdAt);
                }
                $ctx->emit(self::TABLE, $slot, 'user.field_authority_changed', $aggregate, [
                    'fieldKey' => $fieldKey,
                    'authorityType' => $want[0],
                    'provider' => $want[1],
                    'actorUserId' => null,
                ], $createdAt);
            });
        }
    }
}
