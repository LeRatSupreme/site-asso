import { Metadata } from 'next'
import { getProducts, getActiveCategories } from '@/app/actions/cafeteria.actions'
import { POSInterface } from './POSInterface'

export const metadata: Metadata = {
  title: 'Point de Vente',
  description: 'Interface de caisse pour la cafétéria',
}

export default async function POSPage() {
  const categories = await getActiveCategories()
  const allProducts = await getProducts()
  
  // Produits sans catégorie
  const uncategorizedProducts = allProducts.filter(p => !p.categoryId && p.isActive && p.isAvailable)
  
  return (
    <div className="h-[calc(100vh-4rem)] -m-6">
      <POSInterface 
        categories={categories} 
        uncategorizedProducts={uncategorizedProducts}
      />
    </div>
  )
}
