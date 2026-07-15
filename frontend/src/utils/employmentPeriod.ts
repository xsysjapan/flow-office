function nextYearMonth(yearMonth: string): string {
  const [year, month] = yearMonth.split('-').map(Number)
  return `${month === 12 ? year + 1 : year}-${String(month === 12 ? 1 : month + 1).padStart(2, '0')}`
}

/** 入社月から退社月または当月までの、月次勤怠を閲覧できる年月。 */
export function employmentYearMonths(hireDate: string | null | undefined, terminationDate: string | null | undefined, currentYearMonth: string): string[] {
  if (!hireDate) return []

  const lastYearMonth = terminationDate ? terminationDate.slice(0, 7) : currentYearMonth
  const firstYearMonth = hireDate.slice(0, 7)
  if (firstYearMonth > lastYearMonth) return []

  const yearMonths: string[] = []
  for (let yearMonth = firstYearMonth; yearMonth <= lastYearMonth; yearMonth = nextYearMonth(yearMonth)) {
    yearMonths.push(yearMonth)
  }
  return yearMonths
}