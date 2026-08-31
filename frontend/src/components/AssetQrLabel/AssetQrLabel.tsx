import { QRCodeSVG } from 'qrcode.react'

export interface AssetQrLabelProps {
  qrUrl: string
  assetNo: string
  name: string
  size?: number
}

/**
 * spec 論点7-2: 備品詳細画面のみに存在するQR画像描画箇所。物理的な備品に貼るラベル用に、
 * QR(URL)+管理番号+名称を大きめのフォントで表示する印刷向けレイアウト。コンビニの
 * ネットプリント等でブラウザの印刷機能からそのまま出力できることを想定し、`.no-print`と
 * 組み合わせた`@media print`スタイルは呼び出し側(AssetDetailPage)で適用する。
 */
export function AssetQrLabel({ qrUrl, assetNo, name, size = 176 }: AssetQrLabelProps) {
  return (
    <div className="asset-qr-label inline-flex flex-col items-center gap-2 rounded-md border border-border bg-white p-4 text-center">
      <div role="img" aria-label={`${assetNo}のQRコード`} className="inline-block bg-white p-2">
        <QRCodeSVG value={qrUrl} size={size} marginSize={0} />
      </div>
      <div className="text-lg font-bold text-black">{assetNo}</div>
      <div className="text-sm text-black">{name}</div>
    </div>
  )
}
