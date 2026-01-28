'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'

const posOrderSchema = z.object({
  items: z.array(z.object({
    productId: z.string(),
    quantity: z.number().min(1),
    price: z.number().min(0),
  })).min(1, 'Le panier est vide'),
  paymentMethod: z.enum(['CASH', 'CARD', 'SUMUP']),
  notes: z.string().optional(),
  customerName: z.string().optional(),
})

// ============================================
// COMMANDES POS (Point de Vente)
// ============================================

export async function createPOSOrder(data: z.infer<typeof posOrderSchema>) {
  try {
    await requireAdmin()

    const validated = posOrderSchema.parse(data)

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

    // Créer une note combinée avec le mode de paiement
    const combinedNotes = [
      `[POS] Paiement: ${validated.paymentMethod}`,
      validated.customerName ? `Client: ${validated.customerName}` : null,
      validated.notes,
    ].filter(Boolean).join(' | ')

    // Créer la commande avec ses items (statut DELIVERED car payée immédiatement)
    const order = await prisma.cafeteriaOrder.create({
      data: {
        userId: (await prisma.user.findFirst({ where: { role: 'ADMIN' } }))!.id, // Associer à un admin
        total,
        notes: combinedNotes,
        status: 'DELIVERED', // Commande POS = payée et livrée immédiatement
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

    revalidatePath('/admin/cafeteria')
    revalidatePath('/admin/cafeteria/pos')
    revalidatePath('/admin/cafeteria/commandes')

    return { 
      success: true, 
      orderId: order.id,
      total: Number(order.total),
    }
  } catch (error) {
    console.error('Create POS order error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

// ============================================
// GESTION DES STOCKS
// ============================================

export async function getProductsWithStock() {
  const products = await prisma.product.findMany({
    where: { isActive: true },
    include: {
      category: {
        select: { id: true, name: true },
      },
    },
    orderBy: [{ stock: 'asc' }, { name: 'asc' }],
  })

  return products.map(product => ({
    ...product,
    price: Number(product.price),
    costPrice: product.costPrice ? Number(product.costPrice) : null,
  }))
}

export async function updateProductStock(productId: string, newStock: number) {
  try {
    await requireAdmin()

    if (newStock < 0) {
      return { success: false, error: 'Le stock ne peut pas être négatif' }
    }

    const product = await prisma.product.update({
      where: { id: productId },
      data: { stock: newStock },
    })

    revalidatePath('/admin/cafeteria')
    revalidatePath('/admin/cafeteria/pos')

    return { 
      success: true, 
      product: {
        ...product,
        price: Number(product.price),
        costPrice: product.costPrice ? Number(product.costPrice) : null,
      },
    }
  } catch (error) {
    console.error('Update product stock error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function adjustProductStock(productId: string, adjustment: number, reason?: string) {
  try {
    await requireAdmin()

    const product = await prisma.product.findUnique({
      where: { id: productId },
    })

    if (!product) {
      return { success: false, error: 'Produit introuvable' }
    }

    const newStock = product.stock + adjustment
    if (newStock < 0) {
      return { success: false, error: 'Le stock résultant serait négatif' }
    }

    const updatedProduct = await prisma.product.update({
      where: { id: productId },
      data: { stock: newStock },
    })

    revalidatePath('/admin/cafeteria')
    revalidatePath('/admin/cafeteria/pos')

    return { 
      success: true, 
      product: {
        ...updatedProduct,
        price: Number(updatedProduct.price),
        costPrice: updatedProduct.costPrice ? Number(updatedProduct.costPrice) : null,
      },
      previousStock: product.stock,
      newStock,
    }
  } catch (error) {
    console.error('Adjust product stock error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function bulkUpdateStock(updates: { productId: string; stock: number }[]) {
  try {
    await requireAdmin()

    const results = await Promise.all(
      updates.map(async ({ productId, stock }) => {
        if (stock < 0) {
          return { productId, success: false, error: 'Stock négatif' }
        }
        try {
          await prisma.product.update({
            where: { id: productId },
            data: { stock },
          })
          return { productId, success: true }
        } catch {
          return { productId, success: false, error: 'Erreur mise à jour' }
        }
      })
    )

    revalidatePath('/admin/cafeteria')
    revalidatePath('/admin/cafeteria/pos')

    const successCount = results.filter(r => r.success).length
    const failCount = results.length - successCount

    return { 
      success: failCount === 0, 
      message: `${successCount} produit(s) mis à jour${failCount > 0 ? `, ${failCount} erreur(s)` : ''}`,
      results,
    }
  } catch (error) {
    console.error('Bulk update stock error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
