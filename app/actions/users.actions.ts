'use server'

import { revalidatePath } from 'next/cache'
import { hash, compare } from 'bcryptjs'
import { prisma } from '@/app/lib/prisma'
import { requireAuth, requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'
import type { Role } from '@prisma/client'

const registerSchema = z.object({
  name: z.string().min(2, 'Le nom doit contenir au moins 2 caractères'),
  email: z.string().email('Email invalide'),
  password: z.string().min(6, 'Le mot de passe doit contenir au moins 6 caractères'),
})

const profileSchema = z.object({
  name: z.string().min(2, 'Le nom doit contenir au moins 2 caractères'),
  email: z.string().email('Email invalide'),
})

const passwordSchema = z.object({
  currentPassword: z.string().min(1, 'Mot de passe actuel requis'),
  newPassword: z.string().min(6, 'Le nouveau mot de passe doit contenir au moins 6 caractères'),
})

export async function registerUser(data: z.infer<typeof registerSchema>) {
  try {
    const validated = registerSchema.parse(data)

    // Vérifier si l'email existe déjà
    const existingUser = await prisma.user.findUnique({
      where: { email: validated.email },
    })

    if (existingUser) {
      return { success: false, error: 'Cet email est déjà utilisé' }
    }

    // Vérifier si les inscriptions sont ouvertes
    const registrationOpen = await prisma.setting.findUnique({
      where: { key: 'registration_open' },
    })

    if (registrationOpen && registrationOpen.value === 'false') {
      return { success: false, error: 'Les inscriptions sont fermées' }
    }

    // Hasher le mot de passe
    const hashedPassword = await hash(validated.password, 12)

    // Créer l'utilisateur
    const user = await prisma.user.create({
      data: {
        name: validated.name,
        email: validated.email,
        password: hashedPassword,
        role: 'ELEVE',
        isActive: true,
      },
    })

    return { success: true, user: { id: user.id, email: user.email } }
  } catch (error) {
    console.error('Register user error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updateProfile(data: z.infer<typeof profileSchema>) {
  try {
    const session = await requireAuth()

    const validated = profileSchema.parse(data)

    // Vérifier si l'email est déjà utilisé par un autre utilisateur
    if (validated.email !== session.user.email) {
      const existingUser = await prisma.user.findUnique({
        where: { email: validated.email },
      })

      if (existingUser) {
        return { success: false, error: 'Cet email est déjà utilisé' }
      }
    }

    await prisma.user.update({
      where: { id: session.user.id },
      data: {
        name: validated.name,
        email: validated.email,
      },
    })

    revalidatePath('/eleve/profil')

    return { success: true }
  } catch (error) {
    console.error('Update profile error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updatePassword(data: z.infer<typeof passwordSchema>) {
  try {
    const session = await requireAuth()

    const validated = passwordSchema.parse(data)

    // Récupérer l'utilisateur avec le mot de passe
    const user = await prisma.user.findUnique({
      where: { id: session.user.id },
      select: { password: true },
    })

    if (!user?.password) {
      return { success: false, error: 'Compte non trouvé' }
    }

    // Vérifier le mot de passe actuel
    const isValid = await compare(validated.currentPassword, user.password)

    if (!isValid) {
      return { success: false, error: 'Mot de passe actuel incorrect' }
    }

    // Hasher et mettre à jour
    const hashedPassword = await hash(validated.newPassword, 12)

    await prisma.user.update({
      where: { id: session.user.id },
      data: { password: hashedPassword },
    })

    return { success: true }
  } catch (error) {
    console.error('Update password error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updateUserRole(userId: string, role: Role) {
  try {
    await requireAdmin()

    await prisma.user.update({
      where: { id: userId },
      data: { role },
    })

    revalidatePath('/admin/users')

    return { success: true }
  } catch (error) {
    console.error('Update user role error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function toggleUserActive(userId: string, isActive: boolean) {
  try {
    await requireAdmin()

    await prisma.user.update({
      where: { id: userId },
      data: { isActive },
    })

    revalidatePath('/admin/users')

    return { success: true }
  } catch (error) {
    console.error('Toggle user active error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function deleteUser(userId: string) {
  try {
    const session = await requireAdmin()

    // Empêcher de se supprimer soi-même
    if (userId === session.user.id) {
      return { success: false, error: 'Vous ne pouvez pas supprimer votre propre compte' }
    }

    // Vérifier les dépendances
    const user = await prisma.user.findUnique({
      where: { id: userId },
      include: {
        _count: {
          select: {
            orders: true,
            eventRegistrations: true,
          },
        },
      },
    })

    if (!user) {
      return { success: false, error: 'Utilisateur non trouvé' }
    }

    if (user._count.orders > 0 || user._count.eventRegistrations > 0) {
      return {
        success: false,
        error: 'Impossible de supprimer un utilisateur avec des commandes ou inscriptions',
      }
    }

    await prisma.user.delete({
      where: { id: userId },
    })

    revalidatePath('/admin/users')

    return { success: true }
  } catch (error) {
    console.error('Delete user error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
