<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * meta_data に created-at が無い user.* イベントの発生日時(論点19 決定10-11)。
 *
 * - user.created_from_sso_login・user.onboarded_as_admin・user.hire_date_set: 利用者(集約UUID=利用者id)の現在の
 *   users 行の created_at。
 * - user.logged_in: payload の loggedInAt(書式は meta と同じ Y-m-d H:i:s にそろえる)。
 * - それ以外の user.* と、利用者の行が無い・created_at が無い・loggedInAt が無い・解釈できない場合は列 created_at のまま
 *   (null を返す。呼び出し側は既定の StoredEventRow::occurredAt を使う)。
 *
 * 対象は StoredEventRow::hasUsableMetaCreatedAt() が false の行だけ。meta が使える行は変えない(決定10-8)。
 * 作り直しの最初に apply() で全行へ当てはめ、以降はすべて StoredEventRow::occurredAt() の値を使う(照合・導出・写しで食い違わない)。
 * 純粋な変換(DBには触れない)。読み込みは EventStoreRebuildWriter::readSeededData の users を使う。
 */
final class UserEventOccurredAt
{
    /** 発生日時を利用者の行の created_at から取った。 */
    public const SOURCE_USER_ROW = 'user_row_created_at';

    /** 発生日時を payload の loggedInAt から取った。 */
    public const SOURCE_LOGGED_IN_AT = 'logged_in_at';

    /** 行の created_at を使う種類だが、利用者の行が無い(または created_at が無い)ため列のまま。 */
    public const COLUMN_NO_USER_ROW = 'column_no_user_row';

    /** user.logged_in だが loggedInAt が無い・解釈できないため列のまま。 */
    public const COLUMN_NO_LOGGED_IN_AT = 'column_no_logged_in_at';

    /** 上記以外の user.* のため列のまま。 */
    public const COLUMN_OTHER_USER_EVENT = 'column_other_user_event';

    /** 行の created_at を使う種類(利用者の行を入力にする)。 */
    private const USER_ROW_CLASSES = [
        'user.created_from_sso_login',
        'user.onboarded_as_admin',
        'user.hire_date_set',
    ];

    /** @var array<string, ?string> 利用者id => users.created_at(書式は Y-m-d H:i:s に揃えたもの。無ければ null) */
    private array $createdAtByUser = [];

    /** @var array<string, int> */
    private array $counts = [
        self::SOURCE_USER_ROW => 0,
        self::SOURCE_LOGGED_IN_AT => 0,
        self::COLUMN_NO_USER_ROW => 0,
        self::COLUMN_NO_LOGGED_IN_AT => 0,
        self::COLUMN_OTHER_USER_EVENT => 0,
    ];

    /** @param  list<array<string, mixed>>  $users  ReadModel(users)の現在の行(id・created_at を使う) */
    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            if (! isset($user['id'])) {
                continue;
            }
            $this->createdAtByUser[(string) $user['id']] = $this->normalize($user['created_at'] ?? null);
        }
    }

    /**
     * 行の発生日時の明示指定。対象外(meta が使える行・user.* 以外)は null。対象でも列のままなら null を返し、
     * 理由ごとに件数を数える。apply() からだけ呼ぶ(1行につき1回)。
     */
    public function occurredAt(StoredEventRow $row): ?string
    {
        if (! str_starts_with($row->eventClass, 'user.') || $row->hasUsableMetaCreatedAt()) {
            return null;
        }

        if ($row->eventClass === 'user.logged_in') {
            $at = $this->normalize($row->get('loggedInAt'));
            $this->count($at !== null ? self::SOURCE_LOGGED_IN_AT : self::COLUMN_NO_LOGGED_IN_AT);

            return $at;
        }

        if (in_array($row->eventClass, self::USER_ROW_CLASSES, true)) {
            $at = $row->aggregateUuid !== null ? ($this->createdAtByUser[$row->aggregateUuid] ?? null) : null;
            $this->count($at !== null ? self::SOURCE_USER_ROW : self::COLUMN_NO_USER_ROW);

            return $at;
        }

        $this->count(self::COLUMN_OTHER_USER_EVENT);

        return null;
    }

    /**
     * 作り直しの最初に全行へ当てはめる(論点19 決定10-11)。明示指定のある行は発生日時の上書き(StoredEventRow::occurredAtOverride)を
     * 持つ新しい行にする。以降の照合・導出イベントの置き場所・写しは、すべて StoredEventRow::occurredAt() を通して同じ値を使う。
     *
     * @param  list<StoredEventRow>  $rows
     * @return list<StoredEventRow>
     */
    public function apply(array $rows): array
    {
        $applied = [];
        foreach ($rows as $row) {
            $at = $this->occurredAt($row);
            $applied[] = $at === null ? $row : new StoredEventRow(
                $row->id, $row->aggregateUuid, $row->aggregateVersion, $row->eventClass, $row->payload, $row->meta, $row->createdAt, $at,
            );
        }

        return $applied;
    }

    /** @return array<string, int> 理由ごとの件数(対象になった meta の無い user.* の行の数) */
    public function report(): array
    {
        return $this->counts;
    }

    private function count(string $source): void
    {
        $this->counts[$source]++;
    }

    /** Y-m-d H:i:s に揃える。空・解釈できない値は null。 */
    private function normalize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
