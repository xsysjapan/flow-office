import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router-dom'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthContext.tsx'
import { AppSettingsProvider } from './contexts/AppSettingsContext.tsx'
import { RootErrorFallback } from './components/RootErrorFallback/RootErrorFallback.tsx'
import { UnsavedChangesProvider } from './hooks/useUnsavedChangesGuard.tsx'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false,
      refetchOnWindowFocus: false,
    },
  },
})

function Root() {
  return (
    <AuthProvider>
      <AppSettingsProvider>
        <UnsavedChangesProvider>
          <App />
        </UnsavedChangesProvider>
      </AppSettingsProvider>
    </AuthProvider>
  )
}

const router = createBrowserRouter(
  [{ path: '*', element: <Root />, errorElement: <RootErrorFallback /> }],
  { basename: import.meta.env.BASE_URL },
)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
)
