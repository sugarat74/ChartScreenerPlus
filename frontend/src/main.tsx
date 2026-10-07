import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router'
import './index.css'
import { AuthProvider } from './auth/AuthContext.tsx'
import { I18nProvider } from './i18n/I18nProvider.tsx'
import { router } from './router.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <I18nProvider>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </I18nProvider>
  </StrictMode>,
)
