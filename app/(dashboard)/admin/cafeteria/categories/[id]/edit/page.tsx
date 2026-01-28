import { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { CategoryForm } from '../../CategoryForm'
import { getCategoryById } from '@/app/actions/cafeteria.actions'

export const metadata: Metadata = {
  title: 'Modifier la catégorie',
  description: 'Modifier une catégorie de produits',
}

interface EditCategoryPageProps {
  params: Promise<{ id: string }>
}

export default async function EditCategoryPage({ params }: EditCategoryPageProps) {
  const { id } = await params
  const category = await getCategoryById(id)

  if (!category) {
    notFound()
  }

  return <CategoryForm category={category} />
}
