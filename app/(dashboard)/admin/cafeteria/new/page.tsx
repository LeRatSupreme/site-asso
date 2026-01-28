import { Metadata } from 'next'
import { ProductForm } from '../ProductForm'
import { getCategories } from '@/app/actions/cafeteria.actions'

export const metadata: Metadata = {
  title: 'Nouveau produit',
  description: 'Ajouter un nouveau produit à la cafétéria',
}

export default async function NewProductPage() {
  const categories = await getCategories()

  return <ProductForm categories={categories} />
}
