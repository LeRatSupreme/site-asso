import { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { ProductForm } from '../../ProductForm'
import { getProductById, getCategories } from '@/app/actions/cafeteria.actions'

export const metadata: Metadata = {
  title: 'Modifier le produit',
  description: 'Modifier un produit de la cafétéria',
}

interface EditProductPageProps {
  params: Promise<{ id: string }>
}

export default async function EditProductPage({ params }: EditProductPageProps) {
  const { id } = await params
  const [product, categories] = await Promise.all([
    getProductById(id),
    getCategories(),
  ])

  if (!product) {
    notFound()
  }

  return <ProductForm product={product} categories={categories} />
}
