import { createBrowserRouter } from 'react-router'
import AppLayout from './layouts/AppLayout.tsx'
import AdminPage from './pages/AdminPage.tsx'
import AdminActivityPage from './pages/admin/AdminActivityPage.tsx'
import AdminLayout from './pages/admin/AdminLayout.tsx'
import AdminSessionsPage from './pages/admin/AdminSessionsPage.tsx'
import AdminUserDetailPage from './pages/admin/AdminUserDetailPage.tsx'
import AdminUsersPage from './pages/admin/AdminUsersPage.tsx'
import LandingPage from './pages/LandingPage.tsx'
import LegalPage from './pages/LegalPage.tsx'
import InstrumentChartPage from './pages/InstrumentChartPage.tsx'
import LoginPage from './pages/LoginPage.tsx'
import NotFoundPage from './pages/NotFoundPage.tsx'
import PortalPage from './pages/PortalPage.tsx'
import RegisterPage from './pages/RegisterPage.tsx'
import ScreenerPage from './pages/ScreenerPage.tsx'
import {
  COOKIES_ROUTE,
  LEGAL_NOTICE_ROUTE,
  LOGIN_ROUTE,
  PRIVACY_ROUTE,
  REGISTER_ROUTE,
  routeSegment,
} from './nav.ts'

export const router = createBrowserRouter([
  {
    path: '/',
    element: <AppLayout />,
    children: [
      { index: true, element: <LandingPage /> },
      { path: 'screener', element: <ScreenerPage /> },
      { path: 'chart', element: <InstrumentChartPage /> },
      { path: 'instruments/:ticker', element: <InstrumentChartPage /> },
      {
        // One guard + section tabs for every Admin surface (UI affordance only;
        // the API enforces auth:sanctum + admin).
        path: 'admin',
        element: <AdminLayout />,
        children: [
          { index: true, element: <AdminPage /> },
          { path: 'users', element: <AdminUsersPage /> },
          { path: 'users/:userId', element: <AdminUserDetailPage /> },
          { path: 'sessions', element: <AdminSessionsPage /> },
          { path: 'activity', element: <AdminActivityPage /> },
        ],
      },
      { path: 'portal', element: <PortalPage /> },
      { path: routeSegment(LOGIN_ROUTE), element: <LoginPage /> },
      { path: routeSegment(REGISTER_ROUTE), element: <RegisterPage /> },
      { path: routeSegment(PRIVACY_ROUTE), element: <LegalPage kind="privacy" /> },
      { path: routeSegment(LEGAL_NOTICE_ROUTE), element: <LegalPage kind="notice" /> },
      { path: routeSegment(COOKIES_ROUTE), element: <LegalPage kind="cookies" /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
