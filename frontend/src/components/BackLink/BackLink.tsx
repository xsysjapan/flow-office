import { ChevronLeft } from "lucide-react";
import { Link } from "react-router-dom";
import { useBackTarget } from "../../navigation/backState";
import { cn } from "../../lib/utils";

export interface BackLinkProps {
  /** 戻り先の上書き(省略時は遷移元→親画面の順に解決する)。 */
  to?: string;
  /** 戻り先の画面名の上書き(会社カレンダー名など動的な名前用)。 */
  label?: string;
  className?: string;
}

/** 第2階層以下の画面の左上に置く戻る導線。戻り先が無ければ何も描画しない。 */
export function BackLink({ to, label, className }: BackLinkProps) {
  const target = useBackTarget({ to, label });
  if (!target) return null;

  return (
    <div className={cn("mb-3", className)}>
      <Link
        to={target.to}
        state={target.state}
        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground hover:underline"
      >
        <ChevronLeft className="size-4 shrink-0" aria-hidden="true" />
        {target.label}に戻る
      </Link>
    </div>
  );
}
