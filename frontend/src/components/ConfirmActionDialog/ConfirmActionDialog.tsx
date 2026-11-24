import type { ReactNode } from "react";
import { useState } from "react";
import { Button, type ButtonVariant } from "../Button/Button";
import { ErrorMessage } from "../ErrorMessage/ErrorMessage";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../ui/dialog";

export interface ConfirmActionDialogProps {
  /** ダイアログを開くトリガーボタンのラベル。制御モード(`open`指定)では使わない(トリガーを描画しない)。 */
  triggerLabel?: string;
  triggerVariant?: ButtonVariant;
  title: string;
  description: ReactNode;
  confirmLabel: string;
  /** 確定ボタンの見た目。既定は破壊的操作向けの`danger`。 */
  confirmVariant?: ButtonVariant;
  /** trueの間は確定ボタンを押せない(入力が不正な間など)。 */
  confirmDisabled?: boolean;
  onConfirm:() => void | Promise<unknown>;
  isPending?: boolean;
  error?: unknown;
  /** ダイアログの開閉時に呼ばれる(開いた際に呼び出し側のフォーム状態・ミューテーションをリセットする用途)。 */
  onOpenChange?: (open: boolean) => void;
  /**
   * 制御モード。`open`と`onOpenChange`の両方を渡すと、開閉は呼び出し側が持ち、トリガーボタンを描画しない
   * (「⋯」メニューや一括操作バーなど、別の場所から開く確認に使う)。省略時は従来どおりトリガー内蔵の非制御。
   */
  open?: boolean;
  /** キャンセルボタンを出さない(一括操作の結果表示で「閉じる」だけにする用途)。 */
  hideCancel?: boolean;
  /** trueの間はキャンセルボタンを押せない(実行中は閉じさせない用途)。 */
  cancelDisabled?: boolean;
  /** 説明文と確認/キャンセルボタンの間に表示する追加コンテンツ(理由入力欄など)。 */
  children?: ReactNode;
}

/**
 * 破壊的・取り消せない操作(端末の失効、連携の停止、認証キーの無効化など)を実行する前に
 * 確認を挟むダイアログ。個々のページが独自に同じ形の確認ダイアログを実装しないよう、
 * トリガーボタン・確認文・実行ボタンだけを差し替え可能にした共通コンポーネント。
 */
export function ConfirmActionDialog({
  triggerLabel,
  triggerVariant = "danger",
  title,
  description,
  confirmLabel,
  confirmVariant = "danger",
  confirmDisabled = false,
  onConfirm,
  isPending = false,
  error,
  onOpenChange,
  open,
  hideCancel = false,
  cancelDisabled = false,
  children,
}: ConfirmActionDialogProps) {
  const [internalOpen, setInternalOpen] = useState(false);
  const isControlled = open !== undefined && onOpenChange !== undefined;
  const isOpen = isControlled ? open : internalOpen;

  const handleOpenChange = (next: boolean) => {
    if (!isControlled) setInternalOpen(next);
    onOpenChange?.(next);
  };

  const handleConfirm = async () => {
    try {
      const result = onConfirm();
      if (result instanceof Promise) {
        await result;
        handleOpenChange(false);
      }
    } catch {
      // The caller supplies the mutation error through `error`; keep the dialog open for retry.
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      {!isControlled && (
        <Button
          size="sm"
          variant={triggerVariant}
          onClick={() => handleOpenChange(true)}
        >
          {triggerLabel}
        </Button>
      )}
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {children}
        {error !== undefined && error !== null && (
          <ErrorMessage error={error} />
        )}
        <DialogFooter>
          {!hideCancel && (
            <Button
              variant="secondary"
              disabled={cancelDisabled}
              onClick={() => handleOpenChange(false)}
            >
              キャンセル
            </Button>
          )}
          <Button
            variant={confirmVariant}
            disabled={confirmDisabled}
            isLoading={isPending}
            onClick={() => void handleConfirm()}
          >
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
