import { apiFetch, getToken } from './client'
import type { Attachment } from './types'

export type AttachmentOwnerType = 'workflow_request'

export function fetchAttachments(ownerType: AttachmentOwnerType, ownerId: string): Promise<Attachment[]> {
  return apiFetch('/attachments', { query: { owner_type: ownerType, owner_id: ownerId } })
}

export async function uploadAttachment(
  ownerType: AttachmentOwnerType,
  ownerId: string,
  file: File,
): Promise<Attachment> {
  const formData = new FormData()
  formData.append('owner_type', ownerType)
  formData.append('owner_id', String(ownerId))
  formData.append('file', file)

  const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'
  const token = getToken()

  const response = await fetch(new URL('attachments', `${API_BASE_URL.replace(/\/?$/, '/')}`), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: formData,
  })

  const payload = await response.json()

  if (!response.ok) {
    throw new Error(payload.message ?? 'ファイルのアップロードに失敗しました。')
  }

  return payload as Attachment
}

/**
 * ダウンロードはBearerトークン認証のため、通常の<a href>では認可ヘッダーを送れない。
 * fetchでBlobを取得してから合成クリックでダウンロードさせる。
 */
export async function downloadAttachment(id: number, fileName: string): Promise<void> {
  const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'
  const token = getToken()

  const response = await fetch(new URL(`attachments/${id}/download`, `${API_BASE_URL.replace(/\/?$/, '/')}`), {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })

  if (!response.ok) {
    throw new Error('ファイルのダウンロードに失敗しました。')
  }

  const blob = await response.blob()
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = fileName
  link.click()
  URL.revokeObjectURL(objectUrl)
}
