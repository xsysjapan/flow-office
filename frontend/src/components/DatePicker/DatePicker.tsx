import { useState } from "react";
import { CalendarIcon } from "lucide-react";
import type { CaptionLabelProps } from "react-day-picker";
import { ja } from "react-day-picker/locale";
import { YearMonthGrid } from "../YearMonthPicker/YearMonthGrid";
import { formatDate } from "../../utils/weekDates";
import { Button } from "../ui/button";
import { Calendar } from "../ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "../ui/popover";

export interface DatePickerProps {
  id?: string;
  /** "YYYY-MM-DD" 形式。 */
  value: string | undefined;
  onChange: (date: string | undefined) => void;
  placeholder?: string;
  disabled?: boolean;
  /** 「今日」「明日」等の相対日付ショートカットを表示するか。既定は表示する。 */
  showRelativeShortcuts?: boolean;
  /** この日付以降のみ選択可にする(native input[type=date]のminと同じくmin自体も選択可)。"YYYY-MM-DD"形式。 */
  min?: string;
  /** この日付以前のみ選択可にする(native input[type=date]のmaxと同じくmax自体も選択可)。"YYYY-MM-DD"形式。 */
  max?: string;
  /** ラベル要素を使わずアクセシブルネームを付ける場合に指定する(native input[type=date]のaria-labelと同じ用途)。 */
  "aria-label"?: string;
}

/** "YYYY-MM-DD" をタイムゾーン変換をせず、その日のローカル日付として`Date`に変換する。 */
function parseDateValue(value: string | undefined): Date | undefined {
  if (!value) return undefined;
  const parsed = new Date(`${value}T00:00:00`);
  return Number.isNaN(parsed.getTime()) ? undefined : parsed;
}

function formatYearMonth(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

/** 「今日」を基準にした相対日付のショートカット一覧。 */
function relativeDateShortcuts(): { label: string; date: string }[] {
  const today = formatDate(new Date());
  return [{ label: "今日", date: today }];
}

/**
 * カレンダーから日付を1件選ぶ入力。値は`<input type="date">`と同じ"YYYY-MM-DD"文字列。
 * 勤務日・適用期間の開始/終了日など、カレンダーから選びたい日付入力全般で使う。
 * 「今日」「明日」等、よく使う相対日付をワンクリックで選べるショートカットを備える。
 * カレンダー上部の年月表示をクリックすると年月ピッカーに切り替わり、遠い過去の年へも
 * 素早く移動できる(月を選ぶと日付選択画面に戻り、その月の日付を選べる)。
 */
export function DatePicker({
  id,
  value,
  onChange,
  placeholder = "日付を選択",
  disabled,
  showRelativeShortcuts = true,
  min,
  max,
  "aria-label": ariaLabel,
}: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<"day" | "yearMonth">("day");
  const selected = parseDateValue(value);
  const minDate = parseDateValue(min);
  const maxDate = parseDateValue(max);
  const [month, setMonth] = useState<Date>(
    () => selected ?? minDate ?? maxDate ?? new Date(),
  );

  const selectDate = (date: string | undefined) => {
    setOpen(false);
    onChange(date);
  };

  const handleOpenChange = (nextOpen: boolean) => {
    if (nextOpen) {
      setMode("day");
      setMonth(selected ?? minDate ?? maxDate ?? new Date());
    }
    setOpen(nextOpen);
  };

  const selectYearMonth = (yearMonth: string) => {
    const [year, monthNumber] = yearMonth.split("-").map(Number);
    setMonth(new Date(year, monthNumber - 1, 1));
    setMode("day");
  };

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          variant="outline"
          disabled={disabled}
          aria-label={ariaLabel}
          className={`w-full justify-start px-3 font-normal ${value ? "text-foreground" : "text-muted-foreground"}`}
        >
          <CalendarIcon className="size-4 shrink-0 text-muted-foreground" />
          <span className="truncate">{value ?? placeholder}</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-auto p-0"
        align="start"
        collisionPadding={16}
      >
        {mode === "day" ? (
          <>
            {(showRelativeShortcuts || value) && (
              <div className="flex min-h-11 items-center justify-end gap-1 border-b border-border bg-muted/30 px-2 py-1.5">
                {showRelativeShortcuts &&
                  relativeDateShortcuts()
                    .filter(
                      (shortcut) =>
                        (!min || shortcut.date >= min) &&
                        (!max || shortcut.date <= max),
                    )
                    .map((shortcut) => (
                      <Button
                        key={shortcut.label}
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => selectDate(shortcut.date)}
                      >
                        {shortcut.label}
                      </Button>
                    ))}
                {value && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => selectDate(undefined)}
                  >
                    クリア
                  </Button>
                )}
              </div>
            )}
            <Calendar
              mode="single"
              required
              locale={ja}
              labels={{
                labelPrevious: () => "前の月へ",
                labelNext: () => "次の月へ",
              }}
              selected={selected}
              month={month}
              onMonthChange={setMonth}
              disabled={[
                ...(minDate ? [{ before: minDate }] : []),
                ...(maxDate ? [{ after: maxDate }] : []),
              ]}
              onSelect={(date) => selectDate(formatDate(date))}
              components={{
                CaptionLabel: ({
                  children,
                  className,
                  ...captionProps
                }: CaptionLabelProps) => (
                  <button
                    type="button"
                    className={`${className ?? ""} rounded-md px-2 hover:bg-accent hover:text-accent-foreground`}
                    onClick={() => setMode("yearMonth")}
                    {...captionProps}
                  >
                    {children}
                  </button>
                ),
              }}
            />
          </>
        ) : (
          <>
            <div className="flex min-h-11 items-center justify-start border-b border-border bg-muted/30 px-2 py-1.5">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setMode("day")}
              >
                日付選択に戻る
              </Button>
            </div>
            <YearMonthGrid
              initialYearMonth={formatYearMonth(month)}
              selectedYearMonth={formatYearMonth(month)}
              min={min?.slice(0, 7)}
              max={max?.slice(0, 7)}
              onSelect={selectYearMonth}
            />
          </>
        )}
      </PopoverContent>
    </Popover>
  );
}
