<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;

/**
 * 利用者の作成系イベントに、再生しても変わらないようにタイムゾーン・テナントIDを書き足す補正の計画を作る
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点2)。読み取りのみ(`SystemSetting::current()`は行を作るので使わない)。
 *
 * 対象とキー:
 *  1. user.created_manually の attributes.timezone
 *  2. user.onboarded_as_admin・user.created_from_sso_login・user.synced_from_ms365 の timezone
 *  3. external_hr.import_applied の、is_new が true の行の rows[i].timezone
 *  4. user.onboarded_as_admin・user.created_from_sso_login・user.synced_from_ms365・user.sso_account_linked の externalTenantId(キーが無いもの)
 *
 * タイムゾーンの値: その利用者の users.timezone を後で書くイベント(stored_events.id が後のもの)が無ければ、ライブの users.timezone。
 * あれば(または synced・HR取込で新規作成にならないイベントなら)、system_settings.updated の履歴から作成の時点の既定値を復元する。
 * テナントIDの値: 同じ entraUserId のライブの external_identities(MICROSOFT_ENTRA)の値(nullも明示)。キーがあって null でライブが null 以外なら、ライブの値で上書きする。
 */
final class UserSettingsAtReplayPlanner
{
    public const CREATED_MANUALLY = 'user.created_manually';

    public const ONBOARDED_AS_ADMIN = 'user.onboarded_as_admin';

    public const CREATED_FROM_SSO_LOGIN = 'user.created_from_sso_login';

    public const SYNCED_FROM_MS365 = 'user.synced_from_ms365';

    public const SSO_ACCOUNT_LINKED = 'user.sso_account_linked';

    public const PROFILE_UPDATED = 'user.profile_updated';

    public const MIGRATED_FROM_LEGACY = 'user.migrated_from_legacy';

    public const HR_IMPORT_APPLIED = 'external_hr.import_applied';

    public const SETTINGS_UPDATED = 'system_settings.updated';

    /** 補正の対象になるイベント(ユーザーの作成系・連携・HR取込)。 */
    private const TARGET_CLASSES = [
        self::CREATED_MANUALLY, self::ONBOARDED_AS_ADMIN, self::CREATED_FROM_SSO_LOGIN, self::SYNCED_FROM_MS365, self::SSO_ACCOUNT_LINKED, self::HR_IMPORT_APPLIED,
    ];

    /** externalTenantId を持つイベント。 */
    private const TENANT_CLASSES = [self::ONBOARDED_AS_ADMIN, self::CREATED_FROM_SSO_LOGIN, self::SYNCED_FROM_MS365, self::SSO_ACCOUNT_LINKED];

    private const CHUNK = 500;

    /**
     * 未補正のイベントの件数(検出器用。キーが無いイベントの数。ライブの表は読まない)。
     * タイムゾーンは、キーが無い(またはnull)もの。HR取込は is_new が true の行で timezone が無いもの。
     */
    public function uncorrectedCount(): int
    {
        $count = 0;
        DB::table('stored_events')
            ->whereIn('event_class', self::TARGET_CLASSES)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$count): void {
                foreach ($chunk as $row) {
                    $properties = json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR);
                    if ($this->missingTimezonePaths((string) $row->event_class, $properties) !== [] || $this->tenantKeyMissing((string) $row->event_class, $properties)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * 補正の計画を作る(読み取りのみ)。
     *
     * @return array{
     *     events: list<array{id: int, aggregate_uuid: string, aggregate_version: ?int, event_class: string, raw: string, before: array<string, mixed>, after: array<string, mixed>, changes: list<string>}>,
     *     timezone_filled: int, tenant_filled: int, tenant_filled_null: int,
     *     tenant_overwrites: list<array{event_id: int, event_class: string, entra_user_id: string, recorded: null, live: string}>,
     *     timezone_differences: list<array{event_id: int, user_id: string, live: string, history: string}>,
     *     unresolved: list<array{event_id: int, event_class: string, reason: string}>
     * }
     */
    public function plan(): array
    {
        $history = $this->settingsHistory();
        $liveTimezones = DB::table('users')->pluck('timezone', 'id')->all();
        $liveTenants = DB::table('external_identities')->where('provider', 'MICROSOFT_ENTRA')->pluck('external_tenant_id', 'external_subject_id')->all();

        // 1回目: id順に、タイムゾーンを書くイベントの最後のidと、補正の候補を集める。
        /** @var array<string, int> $lastWrite */
        $lastWrite = [];
        /** @var array<string, true> $exists */
        $exists = [];
        $candidates = [];
        $unresolved = [];
        $tenantOverwrites = [];
        $events = [];
        $tenantFilled = 0;
        $tenantFilledNull = 0;

        DB::table('stored_events')
            ->whereIn('event_class', [...self::TARGET_CLASSES, self::PROFILE_UPDATED, self::MIGRATED_FROM_LEGACY])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$lastWrite, &$exists, &$candidates, &$unresolved, &$tenantOverwrites, &$events, &$tenantFilled, &$tenantFilledNull, $liveTenants): void {
                foreach ($chunk as $row) {
                    $id = (int) $row->id;
                    $class = (string) $row->event_class;
                    $properties = json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR);
                    $userId = (string) $row->aggregate_uuid;

                    // タイムゾーンを書くイベントの記録(users.timezone を書くか)と、作成の判定(再生の時点で行があるか)。
                    $timezonePaths = [];
                    $creating = [];
                    switch ($class) {
                        case self::CREATED_MANUALLY:
                        case self::ONBOARDED_AS_ADMIN:
                        case self::CREATED_FROM_SSO_LOGIN:
                            $lastWrite[$userId] = $id;
                            $exists[$userId] = true;
                            $timezonePaths = $this->missingTimezonePaths($class, $properties);
                            $creating = array_fill(0, count($timezonePaths), ['user' => $userId, 'creating' => true]);
                            break;
                        case self::SYNCED_FROM_MS365:
                            $isCreating = ! isset($exists[$userId]);
                            if ($isCreating) {
                                $lastWrite[$userId] = $id;
                            }
                            $exists[$userId] = true;
                            $timezonePaths = $this->missingTimezonePaths($class, $properties);
                            $creating = array_fill(0, count($timezonePaths), ['user' => $userId, 'creating' => $isCreating]);
                            break;
                        case self::HR_IMPORT_APPLIED:
                            foreach ((array) ($properties['rows'] ?? []) as $index => $hrRow) {
                                $hrUser = (string) ($hrRow['user_id'] ?? '');
                                $isCreating = ! isset($exists[$hrUser]);
                                if ($isCreating) {
                                    $lastWrite[$hrUser] = $id;
                                }
                                $exists[$hrUser] = true;
                                if (($hrRow['is_new'] ?? false) === true && ($hrRow['timezone'] ?? null) === null) {
                                    $timezonePaths[] = ['rows', $index, 'timezone'];
                                    $creating[] = ['user' => $hrUser, 'creating' => $isCreating];
                                }
                            }
                            break;
                        case self::PROFILE_UPDATED:
                            if (is_array($properties['after'] ?? null) && array_key_exists('timezone', $properties['after'])) {
                                $lastWrite[$userId] = $id;
                            }
                            break;
                        case self::MIGRATED_FROM_LEGACY:
                            $exists[$userId] = true;
                            if (is_array($properties['attributes'] ?? null) && array_key_exists('timezone', $properties['attributes'])) {
                                $lastWrite[$userId] = $id;
                            }
                            break;
                    }

                    // テナントID(4種類のイベント)。
                    $tenantChange = null;
                    if (in_array($class, self::TENANT_CLASSES, true)) {
                        $entra = $properties['entraUserId'] ?? null;
                        if (! array_key_exists('externalTenantId', $properties)) {
                            if ($entra === null) {
                                $tenantChange = ['value' => null, 'null' => true];
                            } elseif (array_key_exists($entra, $liveTenants)) {
                                $tenantChange = ['value' => $liveTenants[$entra], 'null' => $liveTenants[$entra] === null];
                            } else {
                                $unresolved[] = ['event_id' => $id, 'event_class' => $class, 'reason' => "externalTenantId を補えません(entraUserId={$entra} のライブの external_identities(MICROSOFT_ENTRA)が無い)"];
                            }
                        } elseif ($properties['externalTenantId'] === null && $entra !== null && ($liveTenants[$entra] ?? null) !== null) {
                            $tenantChange = ['value' => $liveTenants[$entra], 'null' => false, 'overwrite' => true];
                            $tenantOverwrites[] = ['event_id' => $id, 'event_class' => $class, 'entra_user_id' => (string) $entra, 'recorded' => null, 'live' => (string) $liveTenants[$entra]];
                        }
                    }

                    if ($timezonePaths === [] && $tenantChange === null) {
                        continue;
                    }

                    $event = [
                        'id' => $id,
                        'aggregate_uuid' => $userId,
                        'aggregate_version' => $row->aggregate_version !== null ? (int) $row->aggregate_version : null,
                        'event_class' => $class,
                        'raw' => (string) $row->event_properties,
                        'before' => $properties,
                        'after' => $properties,
                        'changes' => [],
                    ];
                    if ($tenantChange !== null) {
                        $event['after']['externalTenantId'] = $tenantChange['value'];
                        $event['changes'][] = 'externalTenantId: '.(array_key_exists('externalTenantId', $properties) ? 'null' : '(なし)').' → '.($tenantChange['value'] ?? 'null');
                        if ($tenantChange['overwrite'] ?? false) {
                            // 上書きは、キーが無いものの補完とは別に数える。
                        } elseif ($tenantChange['null']) {
                            $tenantFilledNull++;
                        } else {
                            $tenantFilled++;
                        }
                    }
                    $events[$id] = $event;
                    foreach ($timezonePaths as $i => $path) {
                        $candidates[] = ['event_id' => $id, 'path' => $path] + $creating[$i];
                    }
                }
            });

        // 2回目: タイムゾーンの値を決める。
        $timezoneFilled = 0;
        $differences = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['event_id'];
            $user = $candidate['user'];
            $historyValue = $this->historyAt($history, $id);
            $laterWriter = ($lastWrite[$user] ?? 0) > $id;
            if (! $candidate['creating'] || $laterWriter) {
                $value = $historyValue;
            } else {
                $value = $liveTimezones[$user] ?? $historyValue;
                if (isset($liveTimezones[$user]) && $historyValue !== null && $historyValue !== $liveTimezones[$user]) {
                    $differences[] = ['event_id' => $id, 'user_id' => $user, 'live' => (string) $liveTimezones[$user], 'history' => $historyValue];
                }
            }
            if ($value === null) {
                $unresolved[] = ['event_id' => $id, 'event_class' => $events[$id]['event_class'], 'reason' => "利用者 {$user} のタイムゾーンを補えません(ライブの利用者の行が無く、設定の履歴(system_settings.updated・ライブの設定)からも復元できない)"];

                continue;
            }
            $this->setPath($events[$id]['after'], $candidate['path'], $value);
            $events[$id]['changes'][] = implode('.', $candidate['path']).': (なし) → '.$value;
            $timezoneFilled++;
        }

        return [
            'events' => array_values($events),
            'timezone_filled' => $timezoneFilled,
            'tenant_filled' => $tenantFilled,
            'tenant_filled_null' => $tenantFilledNull,
            'tenant_overwrites' => $tenantOverwrites,
            'timezone_differences' => $differences,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * キーが無い(またはnull)のタイムゾーンの場所。
     *
     * @param  array<string, mixed>  $properties
     * @return list<list<string|int>>
     */
    private function missingTimezonePaths(string $class, array $properties): array
    {
        return match ($class) {
            self::CREATED_MANUALLY => (($properties['attributes']['timezone'] ?? null) === null) ? [['attributes', 'timezone']] : [],
            self::ONBOARDED_AS_ADMIN, self::CREATED_FROM_SSO_LOGIN, self::SYNCED_FROM_MS365 => (($properties['timezone'] ?? null) === null) ? [['timezone']] : [],
            self::HR_IMPORT_APPLIED => $this->missingHrTimezonePaths($properties),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<list<string|int>>
     */
    private function missingHrTimezonePaths(array $properties): array
    {
        $paths = [];
        foreach ((array) ($properties['rows'] ?? []) as $index => $row) {
            if (($row['is_new'] ?? false) === true && ($row['timezone'] ?? null) === null) {
                $paths[] = ['rows', $index, 'timezone'];
            }
        }

        return $paths;
    }

    /** @param  array<string, mixed>  $properties */
    private function tenantKeyMissing(string $class, array $properties): bool
    {
        return in_array($class, self::TENANT_CLASSES, true) && ! array_key_exists('externalTenantId', $properties);
    }

    /**
     * 設定の履歴: system_settings.updated のうち、after に default_timezone のキーがあるものを id 順に。
     *
     * @return list<array{id: int, before: ?string, after: ?string}>
     */
    private function settingsHistory(): array
    {
        $history = [];
        DB::table('stored_events')
            ->where('event_class', self::SETTINGS_UPDATED)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$history): void {
                foreach ($chunk as $row) {
                    $properties = json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($properties['after'] ?? null) || ! array_key_exists('default_timezone', $properties['after'])) {
                        continue;
                    }
                    $history[] = [
                        'id' => (int) $row->id,
                        'before' => isset($properties['before']['default_timezone']) ? (string) $properties['before']['default_timezone'] : null,
                        'after' => $properties['after']['default_timezone'] !== null ? (string) $properties['after']['default_timezone'] : null,
                    ];
                }
            });

        return $history;
    }

    /**
     * 対象のイベントの時点の既定タイムゾーン。前の最後の更新の after、前に更新が無ければ最初の更新の before、更新が1度も無ければライブの設定。
     *
     * @param  list<array{id: int, before: ?string, after: ?string}>  $history
     */
    private function historyAt(array $history, int $eventId): ?string
    {
        if ($history === []) {
            $live = DB::table('system_settings')->value('default_timezone');

            return $live !== null ? (string) $live : null;
        }
        $value = null;
        $found = false;
        foreach ($history as $update) {
            if ($update['id'] < $eventId) {
                $value = $update['after'];
                $found = true;
            }
        }

        return $found ? $value : $history[0]['before'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string|int>  $path
     */
    private function setPath(array &$data, array $path, mixed $value): void
    {
        $target = &$data;
        $last = array_pop($path);
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target[$last] = $value;
    }
}
