import { Navigate, useParams } from 'react-router-dom'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { useAssetByQrToken } from '../../hooks/useAsset'

/**
 * spec 論点7-2: 備品に貼付したQRラベル(スマホカメラで直接読み取る運用)の遷移先。
 * QRの中身は`AssetResource.qr_url`(`/assets/qr/:token`)というURLで、ここで
 * `GET /assets/by-qr/{token}`によりトークンから資産を解決し、備品詳細画面へ
 * リダイレクトする。未ログインならこのルート自体が`RequireAuth`配下にあるため
 * ログイン画面へ誘導され、ログイン後に元のURL(このページ)へ戻ってくる。
 */
export function AssetQrRedirectPage() {
  const { token } = useParams<{ token: string }>()
  const { data: asset, isLoading, error } = useAssetByQrToken(token)

  if (isLoading) return <LoadingState label="備品を確認しています..." />

  if (error || !asset) {
    return <ErrorMessage error={error} fallback="このQRコードに対応する備品が見つかりませんでした。" />
  }

  return <Navigate to={`/assets/${asset.id}`} replace />
}
