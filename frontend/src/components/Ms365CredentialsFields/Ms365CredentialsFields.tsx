import { FormField } from '../FormField/FormField'
import { Checkbox } from '../ui/checkbox'
import { Input } from '../ui/input'

export interface Ms365CredentialsFieldsValue {
  tenantId: string
  clientId: string
  clientSecret: string
  redirectUri: string
  mockEnabled: boolean
}

export interface Ms365CredentialsFieldsProps {
  /** フォーム要素のid衝突を避けるための接頭辞(1画面に複数配置しないため通常は固定でよい)。 */
  idPrefix: string
  value: Ms365CredentialsFieldsValue
  onChange: (value: Ms365CredentialsFieldsValue) => void
  /** 必須項目として表示するか(初回オンボーディングではtrue、設定変更画面ではfalse)。 */
  required?: boolean
  /** クライアントシークレットが既に設定済みかどうか(placeholderの表示に使う)。 */
  clientSecretConfigured?: boolean
}

/**
 * SSOログイン・MS365ユーザー同期・Graphメール送信で共有するEntra ID資格情報の入力群。
 * 初回オンボーディング(`OnboardingPage`)とシステム設定画面(`SystemSettingsPage`)の
 * 両方から使う共通コンポーネント。
 */
export function Ms365CredentialsFields({
  idPrefix,
  value,
  onChange,
  required = false,
  clientSecretConfigured = false,
}: Ms365CredentialsFieldsProps) {
  return (
    <>
      <FormField label="テナントID" htmlFor={`${idPrefix}-tenant-id`} required={required}>
        <Input
          id={`${idPrefix}-tenant-id`}
          value={value.tenantId}
          onChange={(e) => onChange({ ...value, tenantId: e.target.value })}
        />
      </FormField>

      <FormField label="クライアントID" htmlFor={`${idPrefix}-client-id`} required={required}>
        <Input
          id={`${idPrefix}-client-id`}
          value={value.clientId}
          onChange={(e) => onChange({ ...value, clientId: e.target.value })}
        />
      </FormField>

      <FormField label="クライアントシークレット" htmlFor={`${idPrefix}-client-secret`} required={required}>
        <Input
          id={`${idPrefix}-client-secret`}
          type="password"
          placeholder={clientSecretConfigured ? '設定済み(変更する場合のみ入力)' : undefined}
          value={value.clientSecret}
          onChange={(e) => onChange({ ...value, clientSecret: e.target.value })}
        />
      </FormField>

      <FormField label="リダイレクトURI" htmlFor={`${idPrefix}-redirect-uri`} required={required}>
        <Input
          id={`${idPrefix}-redirect-uri`}
          value={value.redirectUri}
          onChange={(e) => onChange({ ...value, redirectUri: e.target.value })}
        />
      </FormField>

      <label className="mb-4 flex items-center gap-2 text-sm text-foreground">
        <Checkbox
          checked={value.mockEnabled}
          onCheckedChange={(checked) => onChange({ ...value, mockEnabled: checked === true })}
        />
        ローカル開発用モックOIDC(mock-oidc)を使う
      </label>
      <p className="mb-4 text-xs text-muted-foreground">
        本番・検証環境では有効にしないこと。有効にすると開発専用の危険なエンドポイント
        (DB初期化)も到達可能になる。
      </p>
    </>
  )
}
