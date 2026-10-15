<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * Entra IDの外部IDを投影する旧イベントへ externalTenantId を補う(論点19 決定9)。
 *
 * 旧イベント(payload に externalTenantId が無いもの)は、再生時に system_settings.m365_tenant_id を読んで
 * external_identities.external_tenant_id を書いていた。その値はイベントに無いため、現在の external_identities の行
 * (provider = MICROSOFT_ENTRA、external_subject_id = イベントの entraUserId)の external_tenant_id を payload に補う。
 *
 * 前提の検査(満たさなければ issues に載せて止める。推測で埋めない):
 * - 現在の MICROSOFT_ENTRA の行の external_tenant_id は null 以外で1種類であること(行がある場合)。
 * - 設定値(system_settings.m365_tenant_id)が null でないときは、その値と一致すること(strcasecmp。LinkExternalIdentityHandler と同じ)。
 *   設定値が null なら一致は検査せず、行の値のみで補完する(mode = 'row_only'。試し実行のレポートに出す)。
 *
 * 補完しないもの(イベントの payload をそのまま残す。Projector は再生時に設定値を読む):
 * - 行が無い(noRow)・行の external_tenant_id が null(nullTenant)・entraUserId が無い。
 *
 * 置き換えは HolidayCalendarDayRefRebuilder と同じく、元の行の位置(anchor・subOrder 0)に payload を補った行を置き、元の行を消す。
 * 純粋な変換(DBには触れない。読み取りは EventStoreRebuildWriter::readSeededData)。
 */
final class ExternalTenantIdRebuilder
{
    public const PROVIDER = 'MICROSOFT_ENTRA';

    /** 外部IDを投影するイベント(UserProjector::linkEntraIdentity を呼ぶもの)。 */
    public const CLASSES = [
        'user.onboarded_as_admin',
        'user.created_from_sso_login',
        'user.synced_from_ms365',
        'user.sso_account_linked',
    ];

    /**
     * @param  list<StoredEventRow>  $rows  stored_events の全行(id順でなくてもよい)
     * @param  list<array<string, mixed>>  $identities  external_identities の現在の行
     * @param  ?string  $systemTenantId  system_settings.m365_tenant_id の現在値
     */
    public function rebuild(array $rows, array $identities, ?string $systemTenantId): ExternalTenantIdRebuild
    {
        usort($rows, fn (StoredEventRow $a, StoredEventRow $b) => $a->id <=> $b->id);

        /** @var array<string, ?string> $tenantBySubject external_subject_id => external_tenant_id(MICROSOFT_ENTRA の行) */
        $tenantBySubject = [];
        /** @var array<string, true> $distinctTenants null 以外の external_tenant_id の種類 */
        $distinctTenants = [];
        $hasEntraRows = false;
        foreach ($identities as $identity) {
            if ((string) ($identity['provider'] ?? '') !== self::PROVIDER) {
                continue;
            }
            $hasEntraRows = true;
            $tenant = isset($identity['external_tenant_id']) && $identity['external_tenant_id'] !== '' ? (string) $identity['external_tenant_id'] : null;
            $tenantBySubject[(string) $identity['external_subject_id']] = $tenant;
            if ($tenant !== null) {
                $distinctTenants[$tenant] = true;
            }
        }

        $issues = [];
        $sourceTenant = null;
        $mode = 'no_rows';
        if ($hasEntraRows) {
            $kinds = count($distinctTenants);
            if ($kinds !== 1) {
                $issues[] = [
                    'key' => '(external_identities '.self::PROVIDER.')',
                    'reason' => "external_tenant_id が null 以外で1種類ではない({$kinds}種類)。旧イベントへ補う前提を満たさないため止める",
                ];
            } else {
                $sourceTenant = (string) array_key_first($distinctTenants);
                if ($systemTenantId !== null) {
                    $mode = 'checked';
                    if (strcasecmp($sourceTenant, $systemTenantId) !== 0) {
                        $issues[] = [
                            'key' => '(external_identities '.self::PROVIDER.')',
                            'reason' => "external_tenant_id({$sourceTenant})が system_settings.m365_tenant_id({$systemTenantId})と違う。旧イベントへ補う前提を満たさないため止める",
                        ];
                    }
                } else {
                    $mode = 'row_only';
                }
            }
        }

        $events = [];
        $consumedIds = [];
        $filledByClass = [];
        $noRow = 0;
        $nullTenant = 0;
        $alreadyComplete = 0;

        if ($issues === []) {
            foreach ($rows as $row) {
                if (! in_array($row->eventClass, self::CLASSES, true)) {
                    continue;
                }
                if (array_key_exists('externalTenantId', $row->payload)) {
                    $alreadyComplete++;

                    continue;
                }
                $subject = $row->get('entraUserId');
                if ($subject === null || $subject === '') {
                    continue;
                }
                if (! array_key_exists((string) $subject, $tenantBySubject)) {
                    $noRow++;

                    continue;
                }
                $tenant = $tenantBySubject[(string) $subject];
                if ($tenant === null) {
                    $nullTenant++;

                    continue;
                }
                if ($row->aggregateUuid === null) {
                    $issues[] = ['key' => (string) $row->id, 'reason' => "{$row->eventClass} の集約UUIDが無く、externalTenantId を補えない"];

                    continue;
                }

                $payload = $row->payload;
                $payload['externalTenantId'] = $tenant;
                $events[] = RebuiltEvent::derived($row, 0, $row->eventClass, $row->aggregateUuid, $payload);
                $consumedIds[] = $row->id;
                $filledByClass[$row->eventClass] = ($filledByClass[$row->eventClass] ?? 0) + 1;
            }
        }

        return new ExternalTenantIdRebuild(
            events: $issues === [] ? $events : [],
            consumedIds: $issues === [] ? $consumedIds : [],
            filledByClass: $filledByClass,
            noRow: $noRow,
            nullTenant: $nullTenant,
            alreadyComplete: $alreadyComplete,
            mode: $mode,
            tenant: $sourceTenant,
            issues: $issues,
        );
    }
}
