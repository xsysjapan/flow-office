import type { ReactNode } from 'react'
import type { PaidLeaveGrantPolicies } from '../../api/types'

function monthsLabel(months: number): string {
  const years = Math.floor(months / 12)
  const remainder = months % 12
  if (years === 0) return `${months}か月`
  if (remainder === 0) return `${years}年`
  return `${years}年${remainder}か月`
}

const WEEKLY_CATEGORY_LABELS: Record<string, string> = {
  '4': '週4日',
  '3': '週3日',
  '2': '週2日',
  '1': '週1日',
}

/**
 * 法定通常付与表・比例付与表(spec.md論点4・14(3)・15-2)を読み取り専用マトリクスとして表示する。
 * 比例付与のトグルは置かない(spec.md論点15-2: 比例付与は`GrantCategoryClassifier`が
 * `WorkStyle`から自動判定するものであり、管理者がルール単位でON/OFFする設定ではないため)。
 * 労働基準法第39条(第1項・第2項: 通常付与、第3項: 比例付与)・同法施行規則第24条の3・
 * 別表第1に基づく最低基準であることを明記し、これを上回るカスタマイズが自由であることを添える
 * (spec.md論点16)。
 */
export function PaidLeaveGrantPolicyMatrix({
  policies,
  normalAction,
  proportionalAction,
}: {
  policies: PaidLeaveGrantPolicies
  /** 通常付与表の見出し横に表示する操作(例: 新バージョン作成ボタン)。 */
  normalAction?: ReactNode
  /** 比例付与表の見出し横に表示する操作(例: 新バージョン作成ボタン)。 */
  proportionalAction?: ReactNode
}) {
  const normalMonths = [...new Set(policies.normal.map((s) => s.continuous_service_months))].sort((a, b) => a - b)
  const proportionalMonths = [...new Set(policies.proportional.map((s) => s.continuous_service_months))].sort(
    (a, b) => a - b,
  )
  const proportionalCategories = ['4', '3', '2', '1'].filter((category) =>
    policies.proportional.some((s) => s.weekly_scheduled_days_category === category),
  )

  function normalDaysFor(months: number): number | undefined {
    return policies.normal.find((s) => s.continuous_service_months === months)?.grant_days
  }

  function proportionalDaysFor(category: string, months: number): number | undefined {
    return policies.proportional.find((s) => s.weekly_scheduled_days_category === category && s.continuous_service_months === months)
      ?.grant_days
  }

  return (
    <div className="flex flex-col gap-6">
      <p className="text-sm text-muted-foreground">
        労働基準法第39条・同法施行規則第24条の3(別表第1)に基づく法定最低付与日数です(自動適用・参考情報)。付与ルールはこの日数を下回ることはできませんが、上回る内容は自由に設定できます。
      </p>

      <div>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-foreground">通常付与(週所定労働日数5日以上、週所定労働時間30時間以上、または年間所定労働日数217日以上)</h3>
          {normalAction}
        </div>
        <div className="overflow-x-auto rounded-md border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/50 text-left text-xs font-medium text-muted-foreground">
                {normalMonths.map((months) => (
                  <th key={months} className="px-3 py-1.5 whitespace-nowrap">
                    {monthsLabel(months)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              <tr>
                {normalMonths.map((months) => (
                  <td key={months} className="px-3 py-1.5 text-foreground">
                    {normalDaysFor(months) ?? '-'}日
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-foreground">比例付与(週所定労働日数4日以下かつ週所定労働時間30時間未満)</h3>
          {proportionalAction}
        </div>
        <div className="overflow-x-auto rounded-md border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/50 text-left text-xs font-medium text-muted-foreground">
                <th className="px-3 py-1.5 whitespace-nowrap">週所定労働日数</th>
                {proportionalMonths.map((months) => (
                  <th key={months} className="px-3 py-1.5 whitespace-nowrap">
                    {monthsLabel(months)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {proportionalCategories.map((category) => (
                <tr key={category} className="border-t border-border last:border-b-0">
                  <td className="px-3 py-1.5 font-medium text-foreground">{WEEKLY_CATEGORY_LABELS[category] ?? category}</td>
                  {proportionalMonths.map((months) => (
                    <td key={months} className="px-3 py-1.5 text-foreground">
                      {proportionalDaysFor(category, months) ?? '-'}日
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
