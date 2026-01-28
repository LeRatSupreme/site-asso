import { Metadata } from 'next'
import { CategoryForm } from '../CategoryForm'

export const metadata: Metadata = {
  title: 'Nouvelle catégorie',
  description: 'Créer une nouvelle catégorie de produits',
}

export default function NewCategoryPage() {
  return <CategoryForm />
}
