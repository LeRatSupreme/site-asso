'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'
import type { Product, ProductCategory } from '@prisma/client'

// ============================================
// HELPER POUR SÉRIALISER LES DECIMAL
// ============================================

type ProductWithCategory = Product & { category: ProductCategory | null }

function serializeProduct<T extends Product>(product: T): T & { price: number; costPrice: number | null } {
  return {
    ...product,
    price: Number(product.price),
    costPrice: product.costPrice ? Number(product.costPrice) : null,
  }
}

function serializeProducts<T extends Product>(products: T[]): (T & { price: number; costPrice: number | null })[] {
  return products.map(serializeProduct)
}

// ============================================
// SCHÉMAS DE VALIDATION
// ============================================

const categorySchema = z.object({
  name: z.string().min(1, 'Le nom est requis'),
  description: z.string().optional(),
  image: z.string().optional(),
  order: z.number().optional(),
  isActive: z.boolean().optional(),
})

const productSchema = z.object({
  name: z.string().min(1, 'Le nom est requis'),
  description: z.string().optional(),
  price: z.number().min(0, 'Le prix doit être positif'),
  costPrice: z.number().min(0, 'Le prix d\'achat doit être positif').optional().nullable(),
  image: z.string().optional(),
  categoryId: z.string().optional().nullable(),
  stock: z.number().min(0, 'Le stock doit être positif').optional(),
  isAvailable: z.boolean().optional(),
  isActive: z.boolean().optional(),
  order: z.number().optional(),
})

// ============================================
// CATÉGORIES
// ============================================

export async function getCategories() {
  return prisma.productCategory.findMany({
    orderBy: [{ order: 'asc' }, { name: 'asc' }],
    include: {
      _count: {
        select: { products: true },
      },
    },
  })
}

export async function getActiveCategories() {
  const categories = await prisma.productCategory.findMany({
    where: { isActive: true },
    orderBy: [{ order: 'asc' }, { name: 'asc' }],
    include: {
      products: {
        where: { isActive: true },
        orderBy: [{ order: 'asc' }, { name: 'asc' }],
      },
    },
  })

  return categories.map(category => ({
    ...category,
    products: serializeProducts(category.products),
  }))
}

export async function getCategoryById(id: string) {
  const category = await prisma.productCategory.findUnique({
    where: { id },
    include: {
      products: {
        orderBy: [{ order: 'asc' }, { name: 'asc' }],
      },
    },
  })

  if (!category) return null

  return {
    ...category,
    products: serializeProducts(category.products),
  }
}

export async function createCategory(data: z.infer<typeof categorySchema>) {
  await requireAdmin()

  const validated = categorySchema.parse(data)

  const category = await prisma.productCategory.create({
    data: validated,
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, category }
}

export async function updateCategory(id: string, data: z.infer<typeof categorySchema>) {
  await requireAdmin()

  const validated = categorySchema.parse(data)

  const category = await prisma.productCategory.update({
    where: { id },
    data: validated,
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, category }
}

export async function deleteCategory(id: string) {
  await requireAdmin()

  // Vérifier s'il y a des produits associés
  const productsCount = await prisma.product.count({
    where: { categoryId: id },
  })

  if (productsCount > 0) {
    return { 
      success: false, 
      error: `Impossible de supprimer cette catégorie car elle contient ${productsCount} produit(s). Déplacez ou supprimez les produits d'abord.` 
    }
  }

  await prisma.productCategory.delete({
    where: { id },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true }
}

// ============================================
// PRODUITS
// ============================================

export async function getProducts(options?: {
  categoryId?: string
  isActive?: boolean
  isAvailable?: boolean
}) {
  const where: Record<string, unknown> = {}

  if (options?.categoryId) {
    where.categoryId = options.categoryId
  }
  if (options?.isActive !== undefined) {
    where.isActive = options.isActive
  }
  if (options?.isAvailable !== undefined) {
    where.isAvailable = options.isAvailable
  }

  const products = await prisma.product.findMany({
    where,
    orderBy: [{ order: 'asc' }, { name: 'asc' }],
    include: {
      category: true,
    },
  })

  return serializeProducts(products)
}

export async function getAvailableProducts() {
  const products = await prisma.product.findMany({
    where: { 
      isActive: true,
      isAvailable: true,
      stock: { gt: 0 },
    },
    orderBy: [{ order: 'asc' }, { name: 'asc' }],
    include: {
      category: true,
    },
  })

  return serializeProducts(products)
}

export async function getProductById(id: string) {
  const product = await prisma.product.findUnique({
    where: { id },
    include: {
      category: true,
    },
  })

  return product ? serializeProduct(product) : null
}

export async function createProduct(data: z.infer<typeof productSchema>) {
  await requireAdmin()

  const validated = productSchema.parse(data)

  const product = await prisma.product.create({
    data: {
      ...validated,
      price: validated.price,
      costPrice: validated.costPrice || null,
    },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, product: serializeProduct(product) }
}

export async function updateProduct(id: string, data: z.infer<typeof productSchema>) {
  await requireAdmin()

  const validated = productSchema.parse(data)

  const product = await prisma.product.update({
    where: { id },
    data: {
      ...validated,
      price: validated.price,
      costPrice: validated.costPrice || null,
    },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, product: serializeProduct(product) }
}

export async function deleteProduct(id: string) {
  await requireAdmin()

  await prisma.product.delete({
    where: { id },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true }
}

// ============================================
// GESTION DES STOCKS
// ============================================

export async function updateStock(id: string, stock: number) {
  await requireAdmin()

  if (stock < 0) {
    return { success: false, error: 'Le stock ne peut pas être négatif' }
  }

  const product = await prisma.product.update({
    where: { id },
    data: { 
      stock,
      isAvailable: stock > 0,
    },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, product: serializeProduct(product) }
}

export async function adjustStock(id: string, adjustment: number) {
  await requireAdmin()

  const product = await prisma.product.findUnique({
    where: { id },
  })

  if (!product) {
    return { success: false, error: 'Produit introuvable' }
  }

  const newStock = product.stock + adjustment
  
  if (newStock < 0) {
    return { success: false, error: 'Stock insuffisant' }
  }

  const updatedProduct = await prisma.product.update({
    where: { id },
    data: { 
      stock: newStock,
      isAvailable: newStock > 0,
    },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, product: serializeProduct(updatedProduct) }
}

export async function toggleProductAvailability(id: string) {
  await requireAdmin()

  const product = await prisma.product.findUnique({
    where: { id },
  })

  if (!product) {
    return { success: false, error: 'Produit introuvable' }
  }

  const updatedProduct = await prisma.product.update({
    where: { id },
    data: { isAvailable: !product.isAvailable },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, product: serializeProduct(updatedProduct) }
}

export async function toggleProductActive(id: string) {
  await requireAdmin()

  const product = await prisma.product.findUnique({
    where: { id },
  })

  if (!product) {
    return { success: false, error: 'Produit introuvable' }
  }

  const updatedProduct = await prisma.product.update({
    where: { id },
    data: { isActive: !product.isActive },
  })

  revalidatePath('/admin/cafeteria')
  revalidatePath('/cafeteria')

  return { success: true, product: serializeProduct(updatedProduct) }
}

// ============================================
// STATISTIQUES
// ============================================

export async function getCafeteriaStats() {
  const [
    totalProducts,
    availableProducts,
    outOfStock,
    categories,
    lowStock,
  ] = await Promise.all([
    prisma.product.count({ where: { isActive: true } }),
    prisma.product.count({ where: { isActive: true, isAvailable: true, stock: { gt: 0 } } }),
    prisma.product.count({ where: { isActive: true, stock: 0 } }),
    prisma.productCategory.count({ where: { isActive: true } }),
    prisma.product.count({ where: { isActive: true, stock: { gt: 0, lte: 5 } } }),
  ])

  return {
    totalProducts,
    availableProducts,
    outOfStock,
    categories,
    lowStock,
  }
}
