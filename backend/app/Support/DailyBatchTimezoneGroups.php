<?php

namespace App\Support;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 日次バッチ(cronから`asOf`未指定で実行される警告・自動付与系ハンドラ)が、社員本人の
 * `users.timezone`(海外拠点・出張者等、会社既定と異なるタイムゾーンの社員がありうる)を
 * 尊重して「今日」を判定するための共通ヘルパー。
 *
 * - `asOf`が明示指定された場合(テスト・手動実行)は、従来通り単一の`$today`として扱う
 *   (グルーピングしない)。
 * - `asOf`が未指定の場合(cronからの実運用)は、指定されたクエリ(在籍中の社員などに絞った
 *   `User::query()`)から実際に使われている`timezone`をdistinctで取得し、タイムゾーンごとに
 *   `Carbon::today($timezone)`で「今日」を計算する。`users.timezone`がNULLの行は
 *   `system_settings.default_timezone`にフォールバックする(`UserProjector`が新規作成時に
 *   既定値を設定するため通常はNULLにならないが、念のため防御する)。
 *
 * 各ハンドラは{@see resolve()}が返すグループを`foreach`し、グループごとに
 * {@see constraint()}でUserクエリ(または`whereHas('user', ...)`)にタイムゾーン条件を
 * 追加した上で、そのグループの`$today`を使って既存の判定ロジックをそのまま実行する。
 */
final class DailyBatchTimezoneGroups
{
    /**
     * @return array<int, array{timezone: string, today: Carbon}>
     */
    public static function resolve(?string $asOf, Builder $activeUsersQuery): array
    {
        $defaultTimezone = SystemSetting::current()->default_timezone;

        if ($asOf !== null) {
            return [[
                'timezone' => $defaultTimezone,
                'today' => Carbon::parse($asOf),
            ]];
        }

        $timezones = (clone $activeUsersQuery)
            ->pluck('timezone')
            ->map(fn (?string $timezone) => $timezone ?? $defaultTimezone)
            ->unique()
            ->values();

        if ($timezones->isEmpty()) {
            $timezones = collect([$defaultTimezone]);
        }

        return $timezones
            ->map(fn (string $timezone) => ['timezone' => $timezone, 'today' => Carbon::today($timezone)])
            ->all();
    }

    /**
     * `$query->where(...)` あるいは `$query->whereHas('user', ...)` にそのまま渡せる
     * クロージャを返す。`$timezone`が`$defaultTimezone`と一致する場合は、`$column`が
     * NULLの行(=既定タイムゾーンにフォールバックする行)も含める。
     */
    public static function constraint(string $timezone, string $defaultTimezone, string $column = 'timezone'): Closure
    {
        return function (Builder $query) use ($timezone, $defaultTimezone, $column): void {
            $query->where($column, $timezone);

            if ($timezone === $defaultTimezone) {
                $query->orWhereNull($column);
            }
        };
    }
}
