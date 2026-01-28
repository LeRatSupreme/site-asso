import { Metadata } from 'next'
import Link from 'next/link'
import { Calendar, Coffee, User, ArrowRight, Clock, CheckCircle2, ChefHat, Package } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { requireAuth } from '@/app/lib/permissions'
import { prisma } from '@/app/lib/prisma'
import { formatDate, formatPrice } from '@/app/lib/utils'

export const metadata: Metadata = {
  title: 'Mon espace',
  description: 'Votre tableau de bord personnel',
}

export default async function EleveDashboardPage() {
  const session = await requireAuth()
  const userId = session.user.id

  // Récupérer les données de l'utilisateur
  const [registrations, cafeteriaOrders, upcomingEvents] = await Promise.all([
    prisma.eventRegistration.findMany({
      where: { userId },
      include: { event: true },
      orderBy: { createdAt: 'desc' },
      take: 5,
    }),
    prisma.cafeteriaOrder.findMany({
      where: { userId },
      orderBy: { createdAt: 'desc' },
      take: 5,
      include: {
        items: {
          include: {
            product: true,
          },
        },
      },
    }),
    prisma.event.findMany({
      where: {
        isPublished: true,
        date: { gte: new Date() },
      },
      orderBy: { date: 'asc' },
      take: 3,
    }),
  ])

  // Sérialiser les commandes
  const orders = cafeteriaOrders.map(order => ({
    ...order,
    total: Number(order.total),
    items: order.items.map(item => ({
      ...item,
      price: Number(item.price),
    })),
  }))

  const ORDER_STATUS_CONFIG: Record<string, { label: string; variant: 'default' | 'success' | 'secondary' | 'destructive' | 'warning' }> = {
    PENDING: { label: 'En attente', variant: 'default' },
    CONFIRMED: { label: 'Confirmée', variant: 'default' },
    PREPARING: { label: 'En préparation', variant: 'warning' },
    READY: { label: 'Prête', variant: 'success' },
    DELIVERED: { label: 'Récupérée', variant: 'secondary' },
    CANCELLED: { label: 'Annulée', variant: 'destructive' },
  }

  return (
    <div className="container py-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold">
          Bonjour, {session.user.name || 'Élève'} 👋
        </h1>
        <p className="text-muted-foreground mt-2">
          Bienvenue dans votre espace personnel
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Colonne principale */}
        <div className="lg:col-span-2 space-y-6">
          {/* Prochains événements */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <Calendar className="h-5 w-5" />
                  Prochains événements
                </CardTitle>
                <CardDescription>
                  Les événements à venir auxquels vous pouvez participer
                </CardDescription>
              </div>
              <Button asChild variant="outline" size="sm">
                <Link href="/events">
                  Voir tous
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
            </CardHeader>
            <CardContent>
              {upcomingEvents.length > 0 ? (
                <div className="space-y-4">
                  {upcomingEvents.map((event) => {
                    const isRegistered = registrations.some(
                      (r) => r.eventId === event.id
                    )
                    return (
                      <div
                        key={event.id}
                        className="flex items-center justify-between p-4 border rounded-lg"
                      >
                        <div>
                          <h4 className="font-medium">{event.title}</h4>
                          <p className="text-sm text-muted-foreground">
                            {formatDate(event.date)} • {event.location}
                          </p>
                        </div>
                        <div className="flex items-center gap-2">
                          {isRegistered && (
                            <Badge variant="success">Inscrit</Badge>
                          )}
                          <Button asChild size="sm" variant="outline">
                            <Link href={`/events/${event.id}`}>Voir</Link>
                          </Button>
                        </div>
                      </div>
                    )
                  })}
                </div>
              ) : (
                <p className="text-muted-foreground text-center py-8">
                  Aucun événement à venir
                </p>
              )}
            </CardContent>
          </Card>

          {/* Mes inscriptions */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <User className="h-5 w-5" />
                Mes inscriptions
              </CardTitle>
              <CardDescription>
                Événements auxquels vous êtes inscrit
              </CardDescription>
            </CardHeader>
            <CardContent>
              {registrations.length > 0 ? (
                <div className="space-y-3">
                  {registrations.map((reg) => (
                    <div
                      key={reg.id}
                      className="flex items-center justify-between p-3 border rounded-lg"
                    >
                      <div>
                        <h4 className="font-medium">{reg.event.title}</h4>
                        <p className="text-sm text-muted-foreground">
                          {formatDate(reg.event.date)}
                        </p>
                      </div>
                      <Badge
                        variant={
                          new Date(reg.event.date) > new Date()
                            ? 'default'
                            : 'secondary'
                        }
                      >
                        {new Date(reg.event.date) > new Date()
                          ? 'À venir'
                          : 'Passé'}
                      </Badge>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-muted-foreground text-center py-8">
                  Aucune inscription pour le moment
                </p>
              )}
            </CardContent>
          </Card>

          {/* Mes commandes */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <Coffee className="h-5 w-5 text-amber-500" />
                  Mes commandes
                </CardTitle>
                <CardDescription>
                  Vos commandes cafétéria récentes
                </CardDescription>
              </div>
              <Button asChild variant="outline" size="sm">
                <Link href="/eleve/commandes">
                  Voir tout
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
            </CardHeader>
            <CardContent>
              {orders.length > 0 ? (
                <div className="space-y-3">
                  {orders.map((order) => (
                    <div
                      key={order.id}
                      className={`flex items-center justify-between p-3 border rounded-lg ${order.status === 'READY' ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : ''}`}
                    >
                      <div>
                        <h4 className="font-medium text-sm">
                          {order.items.map((item, idx) => (
                            <span key={item.id}>
                              {idx > 0 && ', '}
                              {item.quantity}x {item.product.name}
                            </span>
                          ))}
                        </h4>
                        <p className="text-sm text-muted-foreground">
                          {formatDate(order.createdAt)} • {formatPrice(order.total)}
                        </p>
                      </div>
                      <Badge variant={ORDER_STATUS_CONFIG[order.status]?.variant || 'secondary'}>
                        {ORDER_STATUS_CONFIG[order.status]?.label || order.status}
                      </Badge>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8">
                  <Coffee className="h-8 w-8 mx-auto mb-2 text-muted-foreground/50" />
                  <p className="text-muted-foreground">
                    Aucune commande pour le moment
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Actions rapides */}
          <Card>
            <CardHeader>
              <CardTitle>Actions rapides</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <Button asChild className="w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600">
                <Link href="/eleve/cafeteria">
                  <Coffee className="mr-2 h-4 w-4" />
                  Commander à la cafét
                </Link>
              </Button>
              <Button asChild variant="outline" className="w-full">
                <Link href="/events">
                  <Calendar className="mr-2 h-4 w-4" />
                  Voir les événements
                </Link>
              </Button>
              <Button asChild variant="outline" className="w-full">
                <Link href="/eleve/profile">
                  <User className="mr-2 h-4 w-4" />
                  Mon profil
                </Link>
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
