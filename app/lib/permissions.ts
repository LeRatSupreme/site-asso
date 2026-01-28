import { auth } from './auth'
import { isAdmin } from './roles'
import { redirect } from 'next/navigation'
import type { Role } from '@prisma/client'

export type Permission = 
  | 'manage_events'
  | 'manage_orders'
  | 'manage_users'
  | 'manage_pages'
  | 'manage_settings'
  | 'manage_media'
  | 'view_dashboard'
  | 'register_events'
  | 'create_orders'

const rolePermissions: Record<Role, Permission[]> = {
  ADMIN: [
    'manage_events',
    'manage_orders',
    'manage_users',
    'manage_pages',
    'manage_settings',
    'manage_media',
    'view_dashboard',
    'register_events',
    'create_orders',
  ],
  ELEVE: [
    'view_dashboard',
    'register_events',
    'create_orders',
  ],
}

export function hasPermission(role: Role | undefined, permission: Permission): boolean {
  if (!role) return false
  return rolePermissions[role]?.includes(permission) ?? false
}

export function hasAnyPermission(role: Role | undefined, permissions: Permission[]): boolean {
  return permissions.some((permission) => hasPermission(role, permission))
}

export function hasAllPermissions(role: Role | undefined, permissions: Permission[]): boolean {
  return permissions.every((permission) => hasPermission(role, permission))
}

// Server-side permission check with redirect
export async function requirePermission(permission: Permission) {
  const session = await auth()
  
  if (!session?.user) {
    redirect('/login')
  }
  
  if (!hasPermission(session.user.role, permission)) {
    redirect('/unauthorized')
  }
  
  return session
}

// Server-side admin check with redirect
export async function requireAdmin() {
  const session = await auth()
  
  if (!session?.user) {
    redirect('/login')
  }
  
  if (!isAdmin(session.user.role)) {
    redirect('/unauthorized')
  }
  
  return session
}

// Server-side auth check with redirect
export async function requireAuth() {
  const session = await auth()
  
  if (!session?.user) {
    redirect('/login')
  }
  
  return session
}

// Check if user is authenticated (no redirect)
export async function getAuthSession() {
  return await auth()
}
