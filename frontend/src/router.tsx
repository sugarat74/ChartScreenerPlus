import { Navigate, createBrowserRouter } from 'react-router'
import AppLayout from './layouts/AppLayout.tsx'
import AdminPage from './pages/AdminPage.tsx'
import ChartPage from './pages/ChartPage.tsx'
import LoginPage from './pages/LoginPage.tsx'
import NotFoundPage from './pages/NotFoundPage.tsx'
import PortalPage from './pages/PortalPage.tsx'
import RegisterPage from './pages/RegisterPage.tsx'
import ScreenerPage from './pages/ScreenerPage.tsx'
import { DEFAULT_ROUTE, LOGIN_ROUTE, REGISTER_ROUTE, routeSegment } from './nav.ts'

export const router = createBrowserRouter([
  {
    path: '/',
    element: <AppLayout />,
    children: [
      { index: true, element: <Navigate to={DEFAULT_ROUTE} replace /> },
      { path: 'screener', element: <ScreenerPage /> },
      { path: 'chart', element: <ChartPage /> },
      { path: 'admin', element: <AdminPage /> },
      { path: 'portal', element: <PortalPage /> },
      { path: routeSegment(LOGIN_ROUTE), element: <LoginPage /> },
      { path: routeSegment(REGISTER_ROUTE), element: <RegisterPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
