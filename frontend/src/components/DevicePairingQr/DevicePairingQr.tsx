import { QRCodeSVG } from 'qrcode.react'

export interface DevicePairingQrProps {
  claimToken: string
  claimUrl: string
  size?: number
}

/**
 * docs/23-usecases-devices.md UC-D002: `<claim_url>?claim_token=<token>` という
 * 単純なURL+クエリ文字列としてQRエンコードする(device_idはQRに含めない。claim token
 * 自体がpersonal_access_tokensに紐づく識別子を兼ねるため)。JSON形式にすると、汎用の
 * QRリーダー(iOSカメラ等)がURL部分だけを抜き出して開こうとし、claim_tokenが失われる
 * ことがあるため、URL自体にクエリ文字列として含める。
 */
export function DevicePairingQr({ claimToken, claimUrl, size = 176 }: DevicePairingQrProps) {
  const payload = `${claimUrl}?claim_token=${encodeURIComponent(claimToken)}`

  return (
    <div role="img" aria-label="端末ペアリング用QRコード" className="inline-block rounded-md border border-border bg-white p-2">
      <QRCodeSVG value={payload} size={size} marginSize={0} />
    </div>
  )
}
