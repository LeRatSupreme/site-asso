'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAuth, requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'
import type { CafeteriaOrderStatus } from '@prisma/client'

const cafeteriaOrderSchema = z.object({
  items: z.array(z.object({
    productId: z.string(),
    quantity: z.number().min(1),
    price: z.number().min(0),
  })).min(1, 'Le panier est vide'),
  notes: z.string().optional(),
})

// ============================================
// COMMANDES CAFÉTÉRIA
// ============================================

export async function createCafeteriaOrder(data: z.infer<typeof cafeteriaOrderSchema>) {
  try {
    const session = await requireAuth()

    const validated = cafeteriaOrderSchema.parse(data)

    // Vérifier le stock de chaque produit
    for (const item of validated.items) {
      const product = await prisma.product.findUnique({
        where: { id: item.productId },
      })

      if (!product) {
        return { success: false, error: `Produit introuvable` }
      }

      if (!product.isAvailable || !product.isActive) {
        return { success: false, error: `${product.name} n'est plus disponible` }
      }

      if (product.stock < item.quantity) {
        return { success: false, error: `Stock insuffisant pour ${product.name}` }
      }
    }

    // Calculer le total
    const total = validated.items.reduce((sum, item) => sum + item.price * item.quantity, 0)

    // Créer la commande avec ses items
    const order = await prisma.cafeteriaOrder.create({
      data: {
        userId: session.user.id,
        total,
        notes: validated.notes,
        status: 'PENDING',
        items: {
          create: validated.items.map(item => ({
            productId: item.productId,
            quantity: item.quantity,
            price: item.price,
          })),
        },
      },
      include: {
        items: {
          include: {
            product: true,
          },
        },
      },
    })

    // Décrémenter les stocks
    for (const item of validated.items) {
      await prisma.product.update({
        where: { id: item.productId },
        data: {
          stock: {
            decrement: item.quantity,
          },
        },
      })
    }

    revalidatePath('/eleve/cafeteria')
    revalidatePath('/admin/cafeteria')
    revalidatePath('/admin/cafeteria/commandes')

    // Sérialiser les Decimal
    const serializedOrder = {
      ...order,
      total: Number(order.total),
      items: order.items.map(item => ({
        ...item,
        price: Number(item.price),
        product: {
          ...item.product,
          price: Number(item.product.price),
          costPrice: item.product.costPrice ? Number(item.product.costPrice) : null,
        },
      })),
    }

    return { success: true, order: serializedOrder }
  } catch (error) {
    console.error('Create cafeteria order error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function getCafeteriaOrders(userId?: string) {
  const where = userId ? { userId } : {}
  
  const orders = await prisma.cafeteriaOrder.findMany({
    where,
    include: {
      user: {
        select: {
          id: true,
          name: true,
          email: true,
        },
      },
      items: {
        include: {
          product: true,
        },
      },
    },
    orderBy: { createdAt: 'desc' },
  })

  // Sérialiser les Decimal
  return orders.map(order => ({
    ...order,
    total: Number(order.total),
    items: order.items.map(item => ({
      ...item,
      price: Number(item.price),
      product: {
        ...item.product,
        price: Number(item.product.price),
        costPrice: item.product.costPrice ? Number(item.product.costPrice) : null,
      },
    })),
  }))
}

export async function updateCafeteriaOrderStatus(id: string, status: CafeteriaOrderStatus) {
  try {
    await requireAdmin()

    await prisma.cafeteriaOrder.update({
      where: { id },
      data: { status },
    })

    revalidatePath('/admin/cafeteria/commandes')
    revalidatePath('/eleve/cafeteria')

    return { success: true }
  } catch (error) {
    console.error('Update cafeteria order status error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function cancelCafeteriaOrder(id: string) {
  try {
    const session = await requireAuth()

    const order = await prisma.cafeteriaOrder.findUnique({
      where: { id },
      include: { items: true },
    })

    if (!order) {
      return { success: false, error: 'Commande introuvable' }
    }

    if (order.userId !== session.user.id) {
      return { success: false, error: 'Action non autorisée' }
    }

    if (order.status !== 'PENDING') {
      return { success: false, error: 'Impossible d\'annuler cette commande' }
    }

    // Remettre les produits en stock
    for (const item of order.items) {
      await prisma.product.update({
        where: { id: item.productId },
        data: {
          stock: {
            increment: item.quantity,
          },
        },
      })
    }

    await prisma.cafeteriaOrder.update({
      where: { id },
      data: { status: 'CANCELLED' },
    })

    revalidatePath('/eleve/cafeteria')
    revalidatePath('/admin/cafeteria/commandes')

    return { success: true }
  } catch (error) {
    console.error('Cancel cafeteria order error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
