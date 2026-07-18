import { QRCodeSVG } from 'qrcode.react'
import { API_BASE_URL } from '../../api/client'

export interface DevicePairingQrProps {
  claimToken: string
  claimUrl?: string
  size?: number
}

/**
 * docs/23-usecases-devices.md UC-D002: claim tokenは`{url, claim_token}`のJSONとして
 * QRエンコードする(device_idはQRに含めない。claim token自体がpersonal_access_tokensに
 * 紐づく識別子を兼ねるため)。
 */
export function DevicePairingQr({ claimToken, claimUrl = `${API_BASE_URL}/devices/pairing/claim`, size = 176 }: DevicePairingQrProps) {
  const payload = JSON.stringify({ url: claimUrl, claim_token: claimToken })

  return (
    <div role="img" aria-label="端末ペアリング用QRコード" className="inline-block rounded-md border border-border bg-white p-2">
      <QRCodeSVG value={payload} size={size} marginSize={0} />
    </div>
  )
}
