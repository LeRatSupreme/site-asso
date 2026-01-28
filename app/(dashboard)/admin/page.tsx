import { Metadata } from 'next'
import Link from 'next/link'
import { Calendar, ShoppingBag, Users, FileText, TrendingUp, ArrowRight, Coffee } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { prisma } from '@/app/lib/prisma'
import { formatDate } from '@/app/lib/utils'

export const metadata: Metadata = {
  title: 'Administration',
  description: 'Panel d\'administration',
}

export default async function AdminDashboardPage() {
  // Statistiques globales
  const [usersCount, eventsCount, ordersCount, registrationsCount] = await Promise.all([
    prisma.user.count(),
    prisma.event.count(),
    prisma.cafeteriaOrder.count(),
    prisma.eventRegistration.count(),
  ])

  // Derniers utilisateurs
  const recentUsers = await prisma.user.findMany({
    orderBy: { createdAt: 'desc' },
    take: 5,
    select: {
      id: true,
      name: true,
      email: true,
      role: true,
      createdAt: true,
    },
  })

  // Dernières commandes cafétéria
  const recentOrders = await prisma.cafeteriaOrder.findMany({
    orderBy: { createdAt: 'desc' },
    take: 5,
    include: {
      user: { select: { name: true, email: true } },
      items: {
        include: { product: true },
        take: 2,
      },
    },
  })

  // Prochains événements
  const upcomingEvents = await prisma.event.findMany({
    where: { date: { gte: new Date() } },
    orderBy: { date: 'asc' },
    take: 5,
    include: {
      _count: { select: { registrations: true } },
    },
  })

  // Stats des commandes cafétéria par statut
  const orderStats = await prisma.cafeteriaOrder.groupBy({
    by: ['status'],
    _count: true,
  })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Dashboard</h1>
        <p className="text-muted-foreground mt-2">
          Vue d&apos;ensemble de votre association
        </p>
      </div>

      {/* Statistiques */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Utilisateurs</CardTitle>
            <Users className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{usersCount}</div>
            <p className="text-xs text-muted-foreground">membres inscrits</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Événements</CardTitle>
            <Calendar className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{eventsCount}</div>
            <p className="text-xs text-muted-foreground">événements créés</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Commandes</CardTitle>
            <ShoppingBag className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{ordersCount}</div>
            <p className="text-xs text-muted-foreground">commandes passées</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Inscriptions</CardTitle>
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{registrationsCount}</div>
            <p className="text-xs text-muted-foreground">inscriptions aux événements</p>
          </CardContent>
        </Card>
      </div>

      {/* Grille principale */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Derniers utilisateurs */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Derniers utilisateurs</CardTitle>
              <CardDescription>Membres récemment inscrits</CardDescription>
            </div>
            <Button asChild variant="outline" size="sm">
              <Link href="/admin/users">
                Voir tous
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {recentUsers.map((user) => (
                <div key={user.id} className="flex items-center justify-between">
                  <div>
                    <p className="font-medium">{user.name || 'Sans nom'}</p>
                    <p className="text-sm text-muted-foreground">{user.email}</p>
                  </div>
                  <Badge variant={user.role === 'ADMIN' ? 'destructive' : 'secondary'}>
                    {user.role}
                  </Badge>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Dernières commandes cafétéria */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle className="flex items-center gap-2">
                <Coffee className="h-5 w-5 text-amber-500" />
                Dernières commandes
              </CardTitle>
              <CardDescription>Commandes cafétéria récentes</CardDescription>
            </div>
            <Button asChild variant="outline" size="sm">
              <Link href="/admin/cafeteria/commandes">
                Voir toutes
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {recentOrders.length > 0 ? (
                recentOrders.map((order) => (
                  <div key={order.id} className="flex items-center justify-between">
                    <div>
                      <p className="font-medium">
                        {order.items.map(i => i.product.name).join(', ')}
                        {order.items.length > 2 && '...'}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {order.user.name || order.user.email} • {Number(order.total).toFixed(2)}€
                      </p>
                    </div>
                    <Badge
                      variant={
                        order.status === 'DELIVERED'
                          ? 'success'
                          : order.status === 'PENDING'
                          ? 'warning'
                          : order.status === 'PREPARING'
                          ? 'default'
                          : order.status === 'READY'
                          ? 'secondary'
                          : order.status === 'CANCELLED'
                          ? 'destructive'
                          : 'default'
                      }
                    >
                      {order.status === 'PENDING' ? 'En attente' :
                       order.status === 'CONFIRMED' ? 'Confirmé' :
                       order.status === 'PREPARING' ? 'En préparation' :
                       order.status === 'READY' ? 'Prêt' :
                       order.status === 'DELIVERED' ? 'Livré' : 'Annulé'}
                    </Badge>
                  </div>
                ))
              ) : (
                <p className="text-muted-foreground text-center py-4">
                  Aucune commande
                </p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Prochains événements */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Prochains événements</CardTitle>
              <CardDescription>Événements à venir</CardDescription>
            </div>
            <Button asChild variant="outline" size="sm">
              <Link href="/admin/events">
                Gérer
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {upcomingEvents.length > 0 ? (
                upcomingEvents.map((event) => (
                  <div key={event.id} className="flex items-center justify-between">
                    <div>
                      <p className="font-medium">{event.title}</p>
                      <p className="text-sm text-muted-foreground">
                        {formatDate(event.date)} • {event._count.registrations} inscrits
                      </p>
                    </div>
                    <Badge variant={event.isPublished ? 'success' : 'secondary'}>
                      {event.isPublished ? 'Publié' : 'Brouillon'}
                    </Badge>
                  </div>
                ))
              ) : (
                <p className="text-muted-foreground text-center py-4">
                  Aucun événement à venir
                </p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Stats commandes */}
        <Card>
          <CardHeader>
            <CardTitle>Commandes par statut</CardTitle>
            <CardDescription>Répartition des commandes cafétéria</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {orderStats.length > 0 ? (
                orderStats.map((stat) => (
                  <div key={stat.status} className="flex items-center justify-between">
                    <span className="font-medium">
                      {stat.status === 'PENDING' ? 'En attente' :
                       stat.status === 'CONFIRMED' ? 'Confirmé' :
                       stat.status === 'PREPARING' ? 'En préparation' :
                       stat.status === 'READY' ? 'Prêt' :
                       stat.status === 'DELIVERED' ? 'Livré' : 'Annulé'}
                    </span>
                    <Badge variant="outline">{stat._count}</Badge>
                  </div>
                ))
              ) : (
                <p className="text-muted-foreground text-center py-4">
                  Aucune donnée
                </p>
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Accès rapides */}
      <Card>
        <CardHeader>
          <CardTitle>Accès rapides</CardTitle>
          <CardDescription>Actions fréquentes</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <Button asChild variant="outline" className="h-auto py-4 flex-col">
              <Link href="/admin/events/new">
                <Calendar className="h-6 w-6 mb-2" />
                Nouvel événement
              </Link>
            </Button>
            <Button asChild variant="outline" className="h-auto py-4 flex-col">
              <Link href="/admin/pages">
                <FileText className="h-6 w-6 mb-2" />
                Éditer les pages
              </Link>
            </Button>
            <Button asChild variant="outline" className="h-auto py-4 flex-col">
              <Link href="/admin/users">
                <Users className="h-6 w-6 mb-2" />
                Gérer les utilisateurs
              </Link>
            </Button>
            <Button asChild variant="outline" className="h-auto py-4 flex-col">
              <Link href="/admin/settings">
                <TrendingUp className="h-6 w-6 mb-2" />
                Paramètres
              </Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
