import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { auth } from '@/app/lib/auth.config'

// Routes publiques (accessibles sans authentification)
const publicRoutes = [
  '/',
  '/events',
  '/presentation',
  '/team',
  '/legal',
  '/privacy',
  '/login',
  '/register',
]

// Routes qui nécessitent le rôle ADMIN
const adminRoutes = [
  '/admin',
]

// Routes qui commencent par ces préfixes sont publiques
const publicPrefixes = [
  '/api/auth',
  '/_next',
  '/favicon',
  '/images',
  '/uploads',
]

export default auth((req) => {
  const { pathname } = req.nextUrl
  const isLoggedIn = !!req.auth
  const userRole = req.auth?.user?.role
  const isActive = req.auth?.user?.isActive

  // Autoriser les routes publiques (préfixes)
  if (publicPrefixes.some((prefix) => pathname.startsWith(prefix))) {
    return NextResponse.next()
  }

  // Autoriser les fichiers statiques
  if (pathname.includes('.')) {
    return NextResponse.next()
  }

  // Vérifier si la route est publique
  const isPublicRoute = publicRoutes.includes(pathname) || 
    pathname.startsWith('/events/') ||
    pathname.startsWith('/api/') && !pathname.startsWith('/api/admin')

  if (isPublicRoute) {
    // Si l'utilisateur est connecté et essaie d'accéder à login/register
    if (isLoggedIn && (pathname === '/login' || pathname === '/register')) {
      const redirectUrl = userRole === 'ADMIN' ? '/admin' : '/eleve'
      return NextResponse.redirect(new URL(redirectUrl, req.url))
    }
    return NextResponse.next()
  }

  // Routes protégées - vérifier l'authentification
  if (!isLoggedIn) {
    const loginUrl = new URL('/login', req.url)
    loginUrl.searchParams.set('callbackUrl', pathname)
    return NextResponse.redirect(loginUrl)
  }

  // Vérifier si le compte est actif
  if (!isActive) {
    return NextResponse.redirect(new URL('/login?error=AccountDisabled', req.url))
  }

  // Vérifier les routes admin
  const isAdminRoute = adminRoutes.some((route) => pathname.startsWith(route))
  if (isAdminRoute && userRole !== 'ADMIN') {
    return NextResponse.redirect(new URL('/unauthorized', req.url))
  }

  // Rediriger vers le bon dashboard selon le rôle
  if (pathname === '/dashboard') {
    const redirectUrl = userRole === 'ADMIN' ? '/admin' : '/eleve'
    return NextResponse.redirect(new URL(redirectUrl, req.url))
  }

  return NextResponse.next()
})

export const config = {
  matcher: [
    /*
     * Match all request paths except for the ones starting with:
     * - _next/static (static files)
     * - _next/image (image optimization files)
     * - favicon.ico (favicon file)
     */
    '/((?!_next/static|_next/image|favicon.ico).*)',
  ],
}
