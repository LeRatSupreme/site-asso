import { Metadata } from 'next'
import Link from 'next/link'
import { ArrowLeft, Plus, Tags } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/app/components/ui/card'
import { getCategories } from '@/app/actions/cafeteria.actions'
import { CategoriesTable } from './CategoriesTable'

export const metadata: Metadata = {
  title: 'Catégories - Cafétéria',
  description: 'Gérer les catégories de produits',
}

export default async function CategoriesPage() {
  const categories = await getCategories()

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" asChild>
            <Link href="/admin/cafeteria">
              <ArrowLeft className="h-5 w-5" />
            </Link>
          </Button>
          <div>
            <div className="flex items-center gap-3 mb-1">
              <div className="p-2 rounded-xl bg-gradient-to-br from-violet-500 to-purple-500 shadow-lg">
                <Tags className="h-5 w-5 text-white" />
              </div>
              <h1 className="text-3xl font-bold">Catégories</h1>
            </div>
            <p className="text-muted-foreground">
              Organisez vos produits en catégories
            </p>
          </div>
        </div>
        <Button asChild>
          <Link href="/admin/cafeteria/categories/new">
            <Plus className="h-4 w-4 mr-2" />
            Nouvelle catégorie
          </Link>
        </Button>
      </div>

      {/* Categories Table */}
      <Card>
        <CardHeader>
          <CardTitle>Toutes les catégories</CardTitle>
        </CardHeader>
        <CardContent>
          <CategoriesTable categories={categories} />
        </CardContent>
      </Card>
    </div>
  )
}
