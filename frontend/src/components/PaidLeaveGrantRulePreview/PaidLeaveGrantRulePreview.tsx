export interface PaidLeaveGrantRuleStepInput {
  continuous_service_months: number
  grant_days: number
}

export interface PaidLeaveGrantRulePreviewProps {
  firstGrantAfterMonths: number
  grantCycleMonths: number
  minAttendanceRate: number
  steps: PaidLeaveGrantRuleStepInput[]
}

/** 経過月数を「6か月」「1年6か月」のような日本語表記にする。 */
function monthsLabel(months: number): string {
  const years = Math.floor(months / 12)
  const remainder = months % 12
  if (years === 0) return `${months}か月`
  if (remainder === 0) return `${years}年`
  return `${years}年${remainder}か月`
}

/**
 * 有給付与ルールの設定値から機械的に組み立てる日本語文プレビューと、経過年数を列見出しにした
 * 付与日数の横並びテーブル(spec.md論点14)。`PaidLeavePolicyPage`の一覧表示とルール編集Pageの
 * 双方から共通で使う(入力途中の値でもリアルタイムに反映できるよう、値は素朴なpropsで受け取る)。
 */
export function PaidLeaveGrantRulePreview({
  firstGrantAfterMonths,
  grantCycleMonths,
  minAttendanceRate,
  steps,
}: PaidLeaveGrantRulePreviewProps) {
  const sortedSteps = [...steps].sort((a, b) => a.continuous_service_months - b.continuous_service_months)

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-foreground">
        入社日から{firstGrantAfterMonths}か月後に最初の付与。以後{grantCycleMonths}か月ごとに、付与テーブルに沿って日数が増えていきます。出勤率が{minAttendanceRate}
        %未満の月は付与されません。
      </p>

      {sortedSteps.length > 0 && (
        <div className="overflow-x-auto rounded-md border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/50 text-left text-xs font-medium text-muted-foreground">
                {sortedSteps.map((step) => (
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
                {sortedSteps.map((step) => (
                  <td key={step.continuous_service_months} className="px-3 py-1.5 text-foreground">
                    {step.grant_days}日
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
