<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 旧形式の backoffice_task.assigned(担当者の設定と同時に暗黙で status を in_review にしていた)を、新しい構成
 * (assigned=担当者のみ + 必要なら status_changed)へ作り直す純粋な変換(docs/changesets/20261122-backoffice-task-reassign 論点6)。
 * DBには触れない。
 *
 * - 集約ごとに id 順に走査し、直前の status と担当者を追跡する(旧 assigned は担当者=assignedUserId・status=in_review)。
 * - 旧形式(payload に previousAssignedUserId キーが無い) assigned: previousAssignedUserId=直前の担当者を足し、直前の status が
 *   in_review 以外なら直後に status_changed(直前 → in_review)を挿入する。変換済みの assigned はそのまま写す(イベント単位の冪等)。
 * - 直前の status が決まらない並び(created より前の旧 assigned)は violations に記録し、rows を空にする。
 * - 旧形式が1件でもあれば(挿入0件でも)変換後の行を返す。影響のある集約(挿入のあった集約)の版を1から振り直し、全行の id を1から振り直す(meta も合わせる)。
 *   旧id→新id・旧id→新しい版の対応(idMap・versionMap)と、meta の history-normalization.source_event_id の付け替えも行う。
 * - 旧形式が無い(targetCount===0)ときは入力のまま。
 */
final class BackOfficeTaskAssignmentSplitter
{
    public const PREFIX = 'backoffice_task.';

    public const ASSIGNED = 'backoffice_task.assigned';

    public const STATUS_CHANGED = 'backoffice_task.status_changed';

    private const IN_REVIEW = 'in_review';

    /**
     * @param  list<array<string, mixed>>  $rows  stored_events の全行(EventStoreRebuildWriter::readRowArrays の形。順不同でもよい)
     */
    public function split(array $rows): BackOfficeTaskAssignmentSplitResult
    {
        usort($rows, fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        $targets = [];
        foreach ($rows as $row) {
            if ($row['aggregate_uuid'] !== null && str_starts_with((string) $row['event_class'], self::PREFIX)) {
                $targets[(string) $row['aggregate_uuid']] = true;
            }
        }

        $out = [];
        $origins = [];
        $state = [];
        $affected = [];
        $violations = [];
        $targetCount = 0;
        $inserted = 0;
        $skipped = 0;
        $previousStatusCounts = [];
        $terminal = [];

        foreach ($rows as $row) {
            $uuid = $row['aggregate_uuid'] !== null ? (string) $row['aggregate_uuid'] : null;
            $class = (string) $row['event_class'];
            if ($uuid === null || ! isset($targets[$uuid]) || ! str_starts_with($class, self::PREFIX)) {
                $out[] = $row;
                $origins[] = (int) $row['id'];

                continue;
            }

            $state[$uuid] ??= ['status' => null, 'assignee' => null];
            $current = &$state[$uuid];
            $payload = $row['event_properties'];

            switch ($class) {
                case 'backoffice_task.created':
                    $current = ['status' => 'not_started', 'assignee' => null];
                    $out[] = $row;
                    $origins[] = (int) $row['id'];
                    break;
                case self::STATUS_CHANGED:
                    $current['status'] = isset($payload['newStatus']) ? (string) $payload['newStatus'] : null;
                    $out[] = $row;
                    $origins[] = (int) $row['id'];
                    break;
                case 'backoffice_task.completed':
                    $current['status'] = 'completed';
                    $out[] = $row;
                    $origins[] = (int) $row['id'];
                    break;
                case self::ASSIGNED:
                    if (array_key_exists('previousAssignedUserId', $payload)) {
                        $skipped++;
                        $current['assignee'] = $payload['assignedUserId'] ?? null;
                        $out[] = $row;
                        $origins[] = (int) $row['id'];
                        break;
                    }

                    $previousStatus = $current['status'];
                    if ($previousStatus === null) {
                        $violations[] = "集約 {$uuid} の id={$row['id']}: assigned より前に created(または status_changed)が無く、直前の status が決まらない";
                        $current['assignee'] = $payload['assignedUserId'] ?? null;
                        $current['status'] = self::IN_REVIEW;
                        $out[] = $row;
                        $origins[] = (int) $row['id'];
                        break;
                    }

                    $targetCount++;
                    $previousStatusCounts[$previousStatus] = ($previousStatusCounts[$previousStatus] ?? 0) + 1;
                    $row['event_properties'] = $payload + ['previousAssignedUserId' => $current['assignee']];
                    $out[] = $row;
                    $origins[] = (int) $row['id'];

                    if ($previousStatus !== self::IN_REVIEW) {
                        $statusChanged = $row;
                        $statusChanged['event_class'] = self::STATUS_CHANGED;
                        $statusChanged['event_properties'] = [
                            'previousStatus' => $previousStatus,
                            'newStatus' => self::IN_REVIEW,
                            'changedByUserId' => $payload['assignedByUserId'] ?? null,
                            'comment' => null,
                        ];
                        $out[] = $statusChanged;
                        $origins[] = null;
                        $inserted++;
                        $affected[$uuid] = true;
                        if (in_array($previousStatus, ['completed', 'cancelled'], true)) {
                            $terminal[$previousStatus][] = $uuid;
                        }
                    }
                    $current['assignee'] = $payload['assignedUserId'] ?? null;
                    $current['status'] = self::IN_REVIEW;
                    break;
                default:
                    $out[] = $row;
                    $origins[] = (int) $row['id'];
            }
            unset($current);
        }

        if ($violations !== []) {
            return new BackOfficeTaskAssignmentSplitResult([], $targetCount, $inserted, $previousStatusCounts, $terminal, $skipped, $violations);
        }
        if ($targetCount === 0) {
            return new BackOfficeTaskAssignmentSplitResult($rows, 0, 0, $previousStatusCounts, $terminal, $skipped, []);
        }

        $versions = [];
        $versionMap = [];
        foreach ($out as $i => $row) {
            $uuid = $row['aggregate_uuid'];
            if ($uuid !== null && isset($affected[(string) $uuid])) {
                $version = ($versions[(string) $uuid] ?? 0) + 1;
                $versions[(string) $uuid] = $version;
                $out[$i]['aggregate_version'] = $version;
                $out[$i]['meta_data']['aggregate-root-version'] = $version;
            }
            if ($origins[$i] !== null && $out[$i]['aggregate_version'] !== null) {
                $versionMap[$origins[$i]] = (int) $out[$i]['aggregate_version'];
            }
        }

        $idMap = [];
        foreach ($out as $i => $row) {
            $id = $i + 1;
            $out[$i]['id'] = $id;
            $out[$i]['meta_data']['stored-event-id'] = $id;
            if ($origins[$i] !== null) {
                $idMap[$origins[$i]] = $id;
            }
        }

        // 補正ログの対象(history-normalization.source_event_id)など、meta が stored_events.id を指すものを新しい id へ付け替える。
        foreach ($out as $i => $row) {
            $source = $row['meta_data']['history-normalization']['source_event_id'] ?? null;
            if ($source !== null && isset($idMap[(int) $source])) {
                $out[$i]['meta_data']['history-normalization']['source_event_id'] = $idMap[(int) $source];
            }
        }

        return new BackOfficeTaskAssignmentSplitResult($out, $targetCount, $inserted, $previousStatusCounts, $terminal, $skipped, [], $idMap, $versionMap);
    }
}
