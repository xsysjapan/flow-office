import type { PaidLeaveGrantCycleType, PaidLeaveGrantRuleStep } from '../../api/types'
import { GRANT_CATEGORIES, GRANT_CATEGORY_LABELS, formatMonthDay, type FiscalYearStart } from '../../lib/paidLeaveGrantRule'

export interface PaidLeaveGrantRulePreviewProps {
  grantCycleType: PaidLeaveGrantCycleType
  /** 既定の会社カレンダーの年度開始日(年度はじめの付与で文言に使う)。 */
  fiscalYearStart: FiscalYearStart
  firstGrantAfterMonths: number
  grantCycleMonths: number
  minAttendanceRate: number
  steps: PaidLeaveGrantRuleStep[]
}

/** 経過月数を「6か月」「1年6か月」のような日本語表記にする。 */
function monthsLabel(months: number): string {
  const years = Math.floor(months / 12)
  const remainder = months % 12
  if (years === 0) return `${months}か月`
  if (remainder === 0) return `${years}年`
  return `${years}年${remainder}か月`
}

/** 付与方式ごとの付与時期の文(出勤率の文の前に付く)。 */
function cycleSentence({
  grantCycleType,
  fiscalYearStart,
  firstGrantAfterMonths,
  grantCycleMonths,
}: Pick<PaidLeaveGrantRulePreviewProps, 'grantCycleType' | 'fiscalYearStart' | 'firstGrantAfterMonths' | 'grantCycleMonths'>): string {
  if (grantCycleType === 'mass_grant_month') {
    return `入社後最初の年度開始日(${formatMonthDay(fiscalYearStart)})と、入社から${firstGrantAfterMonths}か月の早い方に初回を付与。初回が前倒しになるときは、次の段もまとめて付与。以後は毎年の年度開始日。`
  }
  return `入社日から${firstGrantAfterMonths}か月後に最初の付与。以後${grantCycleMonths}か月ごとに、付与テーブルに沿って日数が増えていきます。`
}

/**
 * 有給付与ルールの設定値から機械的に組み立てる日本語文プレビューと、経過年数を列見出しにした
 * 付与日数の横並びテーブル(spec.md論点14)。区分(通常・週4〜1日)ごとに表を分けて出す。
 * `PaidLeavePolicyPage`の一覧表示とルール編集Pageの双方から共通で使う(入力途中の値でも
 * リアルタイムに反映できるよう、値は素朴なpropsで受け取る)。
 */
export function PaidLeaveGrantRulePreview({
  grantCycleType,
  fiscalYearStart,
  firstGrantAfterMonths,
  grantCycleMonths,
  minAttendanceRate,
  steps,
}: PaidLeaveGrantRulePreviewProps) {
  const sentence = cycleSentence({ grantCycleType, fiscalYearStart, firstGrantAfterMonths, grantCycleMonths })

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-foreground">
        {`${sentence}出勤率が${minAttendanceRate}%未満の月は付与されません。`}
      </p>

      {GRANT_CATEGORIES.map((category) => {
        const categorySteps = steps
          .filter((step) => step.grant_category === category)
          .sort((a, b) => a.continuous_service_months - b.continuous_service_months)
        if (categorySteps.length === 0) return null

        return (
          <div key={category} className="flex flex-col gap-1">
            <p className="text-xs font-medium text-muted-foreground">{GRANT_CATEGORY_LABELS[category]}</p>
            <div className="overflow-x-auto rounded-md border border-border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/50 text-left text-xs font-medium text-muted-foreground">
                    {categorySteps.map((step) => (
                      <th key={step.continuous_service_months} className="px-3 py-1.5 whitespace-nowrap">
                        {monthsLabel(step.continuous_service_months)}
                        {step.continuous_service_months === firstGrantAfterMonths && (
                          <span className="mt-0.5 block font-normal text-primary">初回付与はこの列</span>
                        )}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    {categorySteps.map((step) => (
                      <td key={step.continuous_service_months} className="px-3 py-1.5 text-foreground">
                        {step.grant_days}日
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        )
      })}
    </div>
  )
}
