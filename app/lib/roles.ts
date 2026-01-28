import type { Role } from '@prisma/client'

export const ROLES = {
  ADMIN: 'ADMIN' as Role,
  ELEVE: 'ELEVE' as Role,
} as const

export function isAdmin(role: Role | undefined): boolean {
  return role === ROLES.ADMIN
}

export function isEleve(role: Role | undefined): boolean {
  return role === ROLES.ELEVE
}

export function getRoleLabel(role: Role): string {
  const labels: Record<Role, string> = {
    ADMIN: 'Administrateur',
    ELEVE: 'Élève',
  }
  return labels[role] || role
}

export function getRoleBadgeColor(role: Role): string {
  const colors: Record<Role, string> = {
    ADMIN: 'bg-red-100 text-red-800',
    ELEVE: 'bg-blue-100 text-blue-800',
  }
  return colors[role] || 'bg-gray-100 text-gray-800'
}
