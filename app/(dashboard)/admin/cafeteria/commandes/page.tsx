import { Metadata } from 'next'
import { getCafeteriaOrders } from '@/app/actions/orders.actions'
import { CafeteriaOrdersTable } from './CafeteriaOrdersTable'

export const metadata: Metadata = {
  title: 'Commandes Cafétéria',
  description: 'Gérer les commandes de la cafétéria',
}

type CafeteriaOrderStatus = 'PENDING' | 'CONFIRMED' | 'PREPARING' | 'READY' | 'DELIVERED' | 'CANCELLED'

interface CafeteriaOrder {
  id: string
  status: CafeteriaOrderStatus
  total: number
  createdAt: Date
}

export default async function CafeteriaOrdersPage() {
  const orders = await getCafeteriaOrders()

  // Statistiques
  const stats = {
    pending: orders.filter((o: CafeteriaOrder) => o.status === 'PENDING').length,
    confirmed: orders.filter((o: CafeteriaOrder) => o.status === 'CONFIRMED').length,
    preparing: orders.filter((o: CafeteriaOrder) => o.status === 'PREPARING').length,
    ready: orders.filter((o: CafeteriaOrder) => o.status === 'READY').length,
    today: orders.filter((o: CafeteriaOrder) => {
      const orderDate = new Date(o.createdAt)
      const today = new Date()
      return orderDate.toDateString() === today.toDateString()
    }).length,
    todayRevenue: orders
      .filter((o: CafeteriaOrder) => {
        const orderDate = new Date(o.createdAt)
        const today = new Date()
        return orderDate.toDateString() === today.toDateString() && o.status !== 'CANCELLED'
      })
      .reduce((sum: number, o: CafeteriaOrder) => sum + o.total, 0),
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold bg-gradient-to-r from-amber-500 to-orange-500 bg-clip-text text-transparent">
          Commandes Cafétéria
        </h1>
        <p className="text-muted-foreground">
          Gérez les commandes en temps réel
        </p>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <div className="p-4 rounded-xl border bg-card">
          <p className="text-2xl font-bold text-yellow-500">{stats.pending}</p>
          <p className="text-sm text-muted-foreground">En attente</p>
        </div>
        <div className="p-4 rounded-xl border bg-card">
          <p className="text-2xl font-bold text-blue-500">{stats.confirmed}</p>
          <p className="text-sm text-muted-foreground">Confirmées</p>
        </div>
        <div className="p-4 rounded-xl border bg-card">
          <p className="text-2xl font-bold text-orange-500">{stats.preparing}</p>
          <p className="text-sm text-muted-foreground">En préparation</p>
        </div>
        <div className="p-4 rounded-xl border bg-card">
          <p className="text-2xl font-bold text-green-500">{stats.ready}</p>
          <p className="text-sm text-muted-foreground">Prêtes</p>
        </div>
        <div className="p-4 rounded-xl border bg-card">
          <p className="text-2xl font-bold">{stats.today}</p>
          <p className="text-sm text-muted-foreground">Aujourd&apos;hui</p>
        </div>
        <div className="p-4 rounded-xl border bg-card">
          <p className="text-2xl font-bold text-emerald-500">{stats.todayRevenue.toFixed(2)}€</p>
          <p className="text-sm text-muted-foreground">CA du jour</p>
        </div>
      </div>

      {/* Table des commandes */}
      <CafeteriaOrdersTable orders={orders} />
    </div>
  )
}
