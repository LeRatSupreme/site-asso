import { Metadata } from 'next'
import Link from 'next/link'
import Image from 'next/image'
import { Plus, Package, Tags, AlertTriangle, TrendingUp, Coffee, ShoppingBag, Monitor } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Badge } from '@/app/components/ui/badge'
import { getProducts, getCategories, getCafeteriaStats } from '@/app/actions/cafeteria.actions'
import { getCafeteriaOrders } from '@/app/actions/orders.actions'
import { ProductsTable } from './ProductsTable'

export const metadata: Metadata = {
  title: 'Gestion Cafétéria',
  description: 'Gérer les produits de la cafétéria',
}

export default async function CafeteriaPage() {
  const [products, categories, stats, orders] = await Promise.all([
    getProducts(),
    getCategories(),
    getCafeteriaStats(),
    getCafeteriaOrders(),
  ])

  // Stats commandes
  const pendingOrders = orders.filter(o => ['PENDING', 'CONFIRMED', 'PREPARING'].includes(o.status)).length
  const readyOrders = orders.filter(o => o.status === 'READY').length

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-3 mb-2">
            <div className="p-2 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 shadow-lg">
              <Coffee className="h-6 w-6 text-white" />
            </div>
            <h1 className="text-3xl font-bold">Cafétéria</h1>
          </div>
          <p className="text-muted-foreground">
            Gérez les produits, les prix et les stocks de la cafétéria
          </p>
        </div>
        <div className="flex gap-3">
          <Button asChild className="bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700">
            <Link href="/admin/cafeteria/pos">
              <Monitor className="h-4 w-4 mr-2" />
              Caisse (POS)
            </Link>
          </Button>
          <Button asChild variant="outline" className="relative">
            <Link href="/admin/cafeteria/commandes">
              <ShoppingBag className="h-4 w-4 mr-2" />
              Commandes
              {(pendingOrders + readyOrders) > 0 && (
                <Badge className="absolute -top-2 -right-2 h-5 min-w-5 flex items-center justify-center bg-red-500 text-white text-xs">
                  {pendingOrders + readyOrders}
                </Badge>
              )}
            </Link>
          </Button>
          <Button asChild variant="outline">
            <Link href="/admin/cafeteria/categories">
              <Tags className="h-4 w-4 mr-2" />
              Catégories
            </Link>
          </Button>
          <Button asChild>
            <Link href="/admin/cafeteria/new">
              <Plus className="h-4 w-4 mr-2" />
              Nouveau produit
            </Link>
          </Button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <Card>
          <CardHeader className="flex flex-row items-center gap-3 pb-2">
            <div className="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/50">
              <Package className="h-5 w-5 text-blue-600 dark:text-blue-400" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Total produits</p>
              <p className="text-2xl font-bold">{stats.totalProducts}</p>
            </div>
          </CardHeader>
        </Card>
        
        <Card>
          <CardHeader className="flex flex-row items-center gap-3 pb-2">
            <div className="p-2 rounded-lg bg-green-100 dark:bg-green-900/50">
              <TrendingUp className="h-5 w-5 text-green-600 dark:text-green-400" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Disponibles</p>
              <p className="text-2xl font-bold">{stats.availableProducts}</p>
            </div>
          </CardHeader>
        </Card>
        
        <Card>
          <CardHeader className="flex flex-row items-center gap-3 pb-2">
            <div className="p-2 rounded-lg bg-red-100 dark:bg-red-900/50">
              <AlertTriangle className="h-5 w-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Rupture</p>
              <p className="text-2xl font-bold">{stats.outOfStock}</p>
            </div>
          </CardHeader>
        </Card>
        
        <Card>
          <CardHeader className="flex flex-row items-center gap-3 pb-2">
            <div className="p-2 rounded-lg bg-amber-100 dark:bg-amber-900/50">
              <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Stock faible</p>
              <p className="text-2xl font-bold">{stats.lowStock}</p>
            </div>
          </CardHeader>
        </Card>
        
        <Card>
          <CardHeader className="flex flex-row items-center gap-3 pb-2">
            <div className="p-2 rounded-lg bg-violet-100 dark:bg-violet-900/50">
              <Tags className="h-5 w-5 text-violet-600 dark:text-violet-400" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Catégories</p>
              <p className="text-2xl font-bold">{stats.categories}</p>
            </div>
          </CardHeader>
        </Card>
      </div>

      {/* Alerte commandes prêtes */}
      {readyOrders > 0 && (
        <Link href="/admin/cafeteria/commandes" className="block">
          <div className="bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-800 rounded-xl p-4 hover:bg-green-100 dark:hover:bg-green-900/30 transition-colors cursor-pointer">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-full bg-green-500 animate-pulse">
                  <ShoppingBag className="h-5 w-5 text-white" />
                </div>
                <p className="text-sm text-green-700 dark:text-green-400">
                  <strong>{readyOrders} commande(s)</strong> prête(s) à être récupérée(s) !
                </p>
              </div>
              <Badge className="bg-green-500">Voir</Badge>
            </div>
          </div>
        </Link>
      )}

      {/* Alerte commandes en attente */}
      {pendingOrders > 0 && (
        <Link href="/admin/cafeteria/commandes" className="block">
          <div className="bg-yellow-50 dark:bg-yellow-950/30 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 hover:bg-yellow-100 dark:hover:bg-yellow-900/30 transition-colors cursor-pointer">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <ShoppingBag className="h-5 w-5 text-yellow-500" />
                <p className="text-sm text-yellow-700 dark:text-yellow-400">
                  <strong>{pendingOrders} commande(s)</strong> en cours de traitement
                </p>
              </div>
              <Badge variant="outline" className="border-yellow-500 text-yellow-600">Gérer</Badge>
            </div>
          </div>
        </Link>
      )}

      {/* Alerts stock */}
      {stats.outOfStock > 0 && (
        <div className="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-xl p-4">
          <div className="flex items-center gap-3">
            <AlertTriangle className="h-5 w-5 text-red-500" />
            <p className="text-sm text-red-700 dark:text-red-400">
              <strong>{stats.outOfStock} produit(s)</strong> en rupture de stock
            </p>
          </div>
        </div>
      )}

      {stats.lowStock > 0 && (
        <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl p-4">
          <div className="flex items-center gap-3">
            <AlertTriangle className="h-5 w-5 text-amber-500" />
            <p className="text-sm text-amber-700 dark:text-amber-400">
              <strong>{stats.lowStock} produit(s)</strong> avec un stock faible (≤ 5 unités)
            </p>
          </div>
        </div>
      )}

      {/* Products Table */}
      <Card>
        <CardHeader>
          <CardTitle>Tous les produits</CardTitle>
        </CardHeader>
        <CardContent>
          <ProductsTable products={products} categories={categories} />
        </CardContent>
      </Card>
    </div>
  )
}
