import { useEffect, useState } from 'react'
import './App.css'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'

type BackendStatus = 'checking' | 'ok' | 'unreachable'

function App() {
  const [status, setStatus] = useState<BackendStatus>('checking')

  useEffect(() => {
    const healthUrl = API_BASE_URL.replace(/\/api\/?$/, '/up')

    fetch(healthUrl)
      .then((response) => setStatus(response.ok ? 'ok' : 'unreachable'))
      .catch(() => setStatus('unreachable'))
  }, [])

  return (
    <main className="app">
      <h1>flow-office</h1>
      <p>汎用勤怠・申請・バックオフィス処理システム(フロントエンド)</p>
      <p className="status">
        Backend ({API_BASE_URL}): <strong>{status}</strong>
      </p>
    </main>
  )
}

export default App
