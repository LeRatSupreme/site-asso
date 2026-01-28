import { Metadata } from 'next'
import Link from 'next/link'
import { Coffee, Clock, CheckCircle2, ChefHat, Package, XCircle, ShoppingBag, ArrowRight, Sparkles } from 'lucide-react'
import { prisma } from '@/app/lib/prisma'
import { auth } from '@/app/lib/auth'
import { redirect } from 'next/navigation'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent } from '@/app/components/ui/card'
import { Badge } from '@/app/components/ui/badge'
import { formatDate, formatPrice } from '@/app/lib/utils'

export const metadata: Metadata = {
  title: 'Mes commandes',
  description: 'Historique de vos commandes cafétéria',
}

const STATUS_CONFIG = {
  PENDING: { 
    label: 'En attente', 
    color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800',
    icon: Clock,
    gradient: 'from-yellow-500 to-amber-500',
  },
  CONFIRMED: { 
    label: 'Confirmée', 
    color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 border-blue-200 dark:border-blue-800',
    icon: CheckCircle2,
    gradient: 'from-blue-500 to-cyan-500',
  },
  PREPARING: { 
    label: 'En préparation', 
    color: 'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-400 border-violet-200 dark:border-violet-800',
    icon: ChefHat,
    gradient: 'from-violet-500 to-purple-500',
  },
  READY: { 
    label: 'Prête !', 
    color: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 border-green-200 dark:border-green-800',
    icon: Package,
    gradient: 'from-green-500 to-emerald-500',
  },
  DELIVERED: { 
    label: 'Récupérée', 
    color: 'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400 border-gray-200 dark:border-gray-700',
    icon: CheckCircle2,
    gradient: 'from-gray-400 to-gray-500',
  },
  CANCELLED: { 
    label: 'Annulée', 
    color: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 border-red-200 dark:border-red-800',
    icon: XCircle,
    gradient: 'from-red-500 to-rose-500',
  },
}

export default async function OrdersPage() {
  const session = await auth()
  if (!session?.user?.id) {
    redirect('/login')
  }

  const orders = await prisma.cafeteriaOrder.findMany({
    where: { userId: session.user.id },
    orderBy: { createdAt: 'desc' },
    include: {
      items: {
        include: {
          product: true,
        },
      },
    },
  })

  // Sérialiser les Decimal
  const serializedOrders = orders.map(order => ({
    ...order,
    total: Number(order.total),
    items: order.items.map(item => ({
      ...item,
      price: Number(item.price),
      product: {
        ...item.product,
        price: Number(item.product.price),
      },
    })),
  }))

  // Séparer les commandes actives et terminées
  const activeOrders = serializedOrders.filter(o => 
    ['PENDING', 'CONFIRMED', 'PREPARING', 'READY'].includes(o.status)
  )
  const pastOrders = serializedOrders.filter(o => 
    ['DELIVERED', 'CANCELLED'].includes(o.status)
  )

  return (
    <div className="container max-w-5xl mx-auto px-4 py-6 space-y-8">
      {/* Header */}
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-violet-600 to-purple-600 p-8 text-white">
        <div className="absolute top-0 right-0 -mt-8 -mr-8 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
        <div className="absolute bottom-0 left-0 -mb-8 -ml-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
        <div className="relative">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <div className="p-2 rounded-xl bg-white/20 backdrop-blur-sm">
                  <ShoppingBag className="h-6 w-6" />
                </div>
                <h1 className="text-2xl sm:text-3xl font-bold">Mes commandes</h1>
              </div>
              <p className="text-white/80">
                Suivez l&apos;état de vos commandes cafétéria
              </p>
            </div>
            <Button asChild size="lg" className="bg-white/95 text-violet-700 hover:bg-white shadow-lg font-semibold w-full sm:w-auto border border-white/50">
              <Link href="/eleve/cafeteria">
                <Coffee className="mr-2 h-5 w-5" />
                Nouvelle commande
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </div>
          
          {/* Stats rapides */}
          <div className="grid grid-cols-3 gap-3 sm:gap-4 mt-6">
            <div className="bg-white/20 backdrop-blur-sm rounded-xl p-3 sm:p-4 text-center">
              <div className="text-2xl sm:text-3xl font-bold">{serializedOrders.length}</div>
              <div className="text-xs sm:text-sm text-white/80">Total</div>
            </div>
            <div className="bg-white/20 backdrop-blur-sm rounded-xl p-3 sm:p-4 text-center">
              <div className="text-2xl sm:text-3xl font-bold">{activeOrders.length}</div>
              <div className="text-xs sm:text-sm text-white/80">En cours</div>
            </div>
            <div className="bg-white/20 backdrop-blur-sm rounded-xl p-3 sm:p-4 text-center">
              <div className="text-xl sm:text-3xl font-bold">
                {formatPrice(serializedOrders.reduce((sum, o) => sum + (o.status !== 'CANCELLED' ? o.total : 0), 0))}
              </div>
              <div className="text-xs sm:text-sm text-white/80">Dépensé</div>
            </div>
          </div>
        </div>
      </div>

      {/* Commandes en cours */}
      {activeOrders.length > 0 && (
        <div className="space-y-4">
          <div className="flex items-center gap-2 px-1">
            <div className="h-2 w-2 rounded-full bg-green-500 animate-pulse" />
            <h2 className="text-xl font-semibold">Commandes en cours</h2>
            <Badge variant="secondary">{activeOrders.length}</Badge>
          </div>
          
          <div className="grid gap-4 lg:grid-cols-2">
            {activeOrders.map((order) => {
              const statusConfig = STATUS_CONFIG[order.status as keyof typeof STATUS_CONFIG]
              const StatusIcon = statusConfig.icon

              return (
                <Card 
                  key={order.id} 
                  className={`relative overflow-hidden transition-all hover:shadow-lg ${
                    order.status === 'READY' 
                      ? 'ring-2 ring-green-500 ring-offset-2 dark:ring-offset-gray-900' 
                      : 'hover:border-primary/50'
                  }`}
                >
                  {/* Barre de statut colorée */}
                  <div className={`absolute top-0 left-0 right-0 h-1 bg-gradient-to-r ${statusConfig.gradient}`} />
                  
                  <CardContent className="p-5">
                    {/* Header de la commande */}
                    <div className="flex items-start justify-between mb-4">
                      <div>
                        <div className="flex items-center gap-2 mb-1 flex-wrap">
                          <span className="font-mono text-xs px-2 py-1 rounded-full bg-muted">
                            #{order.id.slice(-6).toUpperCase()}
                          </span>
                          <Badge className={`${statusConfig.color} border`}>
                            <StatusIcon className="h-3 w-3 mr-1" />
                            {statusConfig.label}
                          </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                          {formatDate(order.createdAt)}
                        </p>
                      </div>
                      <div className="text-right">
                        <div className="text-2xl font-bold bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
                          {formatPrice(order.total)}
                        </div>
                        <div className="text-xs text-muted-foreground">
                          {order.items.length} article{order.items.length > 1 ? 's' : ''}
                        </div>
                      </div>
                    </div>

                    {/* Liste des produits */}
                    <div className="space-y-2 mb-4">
                      {order.items.map((item) => (
                        <div 
                          key={item.id} 
                          className="flex items-center justify-between py-2 px-3 rounded-lg bg-muted/50"
                        >
                          <div className="flex items-center gap-3">
                            <span className="flex items-center justify-center h-6 w-6 rounded-full bg-primary/10 text-primary text-xs font-bold">
                              {item.quantity}
                            </span>
                            <span className="font-medium">{item.product.name}</span>
                          </div>
                          <span className="text-sm text-muted-foreground">
                            {formatPrice(item.price * item.quantity)}
                          </span>
                        </div>
                      ))}
                    </div>

                    {/* Notes */}
                    {order.notes && (
                      <div className="text-sm text-muted-foreground bg-muted/30 rounded-lg p-3 mb-4">
                        <span className="font-medium">Note :</span> {order.notes}
                      </div>
                    )}

                    {/* Message spécial si prêt */}
                    {order.status === 'READY' && (
                      <div className="flex items-center gap-3 p-4 rounded-xl bg-gradient-to-r from-green-500/10 to-emerald-500/10 border border-green-500/20">
                        <div className="flex-shrink-0">
                          <div className="h-10 w-10 rounded-full bg-green-500 flex items-center justify-center">
                            <Sparkles className="h-5 w-5 text-white" />
                          </div>
                        </div>
                        <div>
                          <p className="font-semibold text-green-700 dark:text-green-400">
                            Commande prête !
                          </p>
                          <p className="text-sm text-green-600 dark:text-green-500">
                            Venez la récupérer à la cafétéria
                          </p>
                        </div>
                      </div>
                    )}
                  </CardContent>
                </Card>
              )
            })}
          </div>
        </div>
      )}

      {/* Historique des commandes */}
      {pastOrders.length > 0 && (
        <div className="space-y-4">
          <h2 className="text-xl font-semibold text-muted-foreground px-1">Historique</h2>
          
          <div className="space-y-3">
            {pastOrders.map((order) => {
              const statusConfig = STATUS_CONFIG[order.status as keyof typeof STATUS_CONFIG]
              const StatusIcon = statusConfig.icon

              return (
                <Card key={order.id} className="bg-muted/30 border-muted">
                  <CardContent className="p-4">
                    <div className="flex items-center justify-between gap-4">
                      <div className="flex items-center gap-4 min-w-0">
                        <div className={`h-10 w-10 rounded-full bg-gradient-to-br ${statusConfig.gradient} flex items-center justify-center opacity-50 flex-shrink-0`}>
                          <StatusIcon className="h-5 w-5 text-white" />
                        </div>
                        <div className="min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-mono text-xs text-muted-foreground">
                              #{order.id.slice(-6).toUpperCase()}
                            </span>
                            <Badge variant="outline" className="text-xs">
                              {statusConfig.label}
                            </Badge>
                          </div>
                          <p className="text-sm text-muted-foreground truncate">
                            {formatDate(order.createdAt)} • {order.items.length} article{order.items.length > 1 ? 's' : ''}
                          </p>
                        </div>
                      </div>
                      <div className="text-lg font-semibold text-muted-foreground flex-shrink-0">
                        {formatPrice(order.total)}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              )
            })}
          </div>
        </div>
      )}

      {/* État vide */}
      {serializedOrders.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="py-16 text-center">
            <div className="inline-flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 dark:from-blue-900/30 dark:to-violet-900/30 mb-6">
              <Coffee className="h-10 w-10 text-violet-500" />
            </div>
            <h3 className="text-xl font-semibold mb-2">Aucune commande</h3>
            <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
              Vous n&apos;avez pas encore passé de commande. Découvrez notre cafétéria !
            </p>
            <Button asChild size="lg" className="bg-gradient-to-r from-blue-600 to-violet-600 hover:from-blue-700 hover:to-violet-700">
              <Link href="/eleve/cafeteria">
                <Coffee className="mr-2 h-5 w-5" />
                Découvrir la cafétéria
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
