function pad(n: number): string {
  return String(n).padStart(2, '0')
}

export function formatDate(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function mondayOf(date: Date): Date {
  const d = new Date(date)
  const dow = d.getDay()
  d.setDate(d.getDate() + (dow === 0 ? -6 : 1 - dow))
  d.setHours(0, 0, 0, 0)
  return d
}

export function addDays(dateStr: string, amount: number): string {
  const d = new Date(`${dateStr}T00:00:00`)
  d.setDate(d.getDate() + amount)
  return formatDate(d)
}

export function weekDates(weekStart: string): string[] {
  return Array.from({ length: 7 }, (_, i) => addDays(weekStart, i))
}

/** "YYYY-MM" の月次勤怠が対象とする、その月の全日付("YYYY-MM-DD")を1日から月末まで並べて返す。 */
export function datesInMonth(yearMonth: string): string[] {
  const [year, month] = yearMonth.split('-').map(Number)
  const daysInMonth = new Date(year, month, 0).getDate()
  return Array.from({ length: daysInMonth }, (_, i) => `${yearMonth}-${pad(i + 1)}`)
}
