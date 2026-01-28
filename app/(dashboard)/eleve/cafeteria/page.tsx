import { Metadata } from 'next'
import { redirect } from 'next/navigation'
import Link from 'next/link'
import { auth } from '@/app/lib/auth'
import { prisma } from '@/app/lib/prisma'
import { getAvailableProducts, getCategories } from '@/app/actions/cafeteria.actions'
import { CafeteriaOrderClient } from './CafeteriaOrderClient'
import { Coffee, ShoppingBag, Clock, ArrowRight } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent } from '@/app/components/ui/card'

export const metadata: Metadata = {
  title: 'Cafétéria',
  description: 'Commander à la cafétéria',
}

export default async function CafeteriaPage() {
  const session = await auth()
  if (!session?.user?.id) {
    redirect('/login')
  }

  // Vérifier si les commandes cafétéria sont activées
  const cafeteriaEnabled = await prisma.setting.findUnique({
    where: { key: 'cafeteria_enabled' },
  })

  // Récupérer les horaires et message de la cafétéria
  const [cafeteriaHours, cafeteriaMessage] = await Promise.all([
    prisma.setting.findUnique({ where: { key: 'cafeteria_hours' } }),
    prisma.setting.findUnique({ where: { key: 'cafeteria_message' } }),
  ])

  if (cafeteriaEnabled?.value === 'false') {
    return (
      <div className="container max-w-5xl mx-auto px-4 py-6 space-y-8">
        {/* Header */}
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-violet-600 to-purple-600 p-8 text-white">
          <div className="absolute top-0 right-0 -mt-8 -mr-8 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
          <div className="absolute bottom-0 left-0 -mb-8 -ml-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
          <div className="relative">
            <div className="flex items-center gap-3 mb-2">
              <div className="p-2 rounded-xl bg-white/20 backdrop-blur-sm">
                <Coffee className="h-6 w-6" />
              </div>
              <h1 className="text-2xl sm:text-3xl font-bold">Cafétéria</h1>
            </div>
            <p className="text-white/80">
              Commander des produits à la cafétéria
            </p>
          </div>
        </div>

        <Card className="border-dashed">
          <CardContent className="py-16 text-center">
            <div className="inline-flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 dark:from-blue-900/30 dark:to-violet-900/30 mb-6">
              <Coffee className="h-10 w-10 text-violet-500" />
            </div>
            <h3 className="text-xl font-semibold mb-2">Cafétéria fermée</h3>
            <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
              La cafétéria est actuellement fermée. Revenez plus tard !
            </p>
          </CardContent>
        </Card>
      </div>
    )
  }

  const [products, categories] = await Promise.all([
    getAvailableProducts(),
    getCategories(),
  ])

  // Compter les produits par catégorie
  const totalProducts = products.length
  const categoriesWithProducts = categories.filter(c => c._count.products > 0).length

  return (
    <div className="container max-w-6xl mx-auto px-4 py-6 space-y-6">
      {/* Header */}
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-violet-600 to-purple-600 p-6 sm:p-8 text-white">
        <div className="absolute top-0 right-0 -mt-8 -mr-8 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
        <div className="absolute bottom-0 left-0 -mb-8 -ml-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
        <div className="relative">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <div className="p-2 rounded-xl bg-white/20 backdrop-blur-sm">
                  <Coffee className="h-6 w-6" />
                </div>
                <h1 className="text-2xl sm:text-3xl font-bold">Cafétéria</h1>
              </div>
              <p className="text-white/80">
                Découvrez nos produits et passez commande
              </p>
            </div>
            <Button asChild size="lg" className="bg-white/95 text-violet-700 hover:bg-white shadow-lg font-semibold w-full sm:w-auto border border-white/50">
              <Link href="/eleve/commandes">
                <ShoppingBag className="mr-2 h-5 w-5" />
                Mes commandes
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </div>
          
          {/* Stats rapides */}
          <div className="grid grid-cols-3 gap-3 sm:gap-4 mt-6">
            <div className="bg-white/20 backdrop-blur-sm rounded-xl p-3 sm:p-4 text-center">
              <div className="text-2xl sm:text-3xl font-bold">{totalProducts}</div>
              <div className="text-xs sm:text-sm text-white/80">Produits</div>
            </div>
            <div className="bg-white/20 backdrop-blur-sm rounded-xl p-3 sm:p-4 text-center">
              <div className="text-2xl sm:text-3xl font-bold">{categoriesWithProducts}</div>
              <div className="text-xs sm:text-sm text-white/80">Catégories</div>
            </div>
            <div className="bg-white/20 backdrop-blur-sm rounded-xl p-3 sm:p-4 text-center flex flex-col items-center justify-center">
              <Clock className="h-5 w-5 sm:h-6 sm:w-6 mb-1" />
              <div className="text-xs sm:text-sm text-white/80">{cafeteriaHours?.value || '10h-14h'}</div>
            </div>
          </div>
        </div>
      </div>

      {/* Message d'information */}
      {cafeteriaMessage?.value && (
        <div className="px-1">
          <div className="p-4 rounded-xl bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300">
            <p className="text-sm">{cafeteriaMessage.value}</p>
          </div>
        </div>
      )}

      <CafeteriaOrderClient 
        products={products} 
        categories={categories} 
      />
    </div>
  )
}
