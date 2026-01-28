'use client'

import { useState } from 'react'
import { 
  Clock, 
  CheckCircle2, 
  ChefHat, 
  Package, 
  XCircle,
  User,
  Eye,
  RefreshCw,
} from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/app/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/app/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/app/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/app/components/ui/select'
import { toast } from '@/app/components/ui/use-toast'
import { updateCafeteriaOrderStatus } from '@/app/actions/orders.actions'
import { formatPrice } from '@/app/lib/utils'
import { useRouter } from 'next/navigation'

type CafeteriaOrderStatus = 'PENDING' | 'CONFIRMED' | 'PREPARING' | 'READY' | 'DELIVERED' | 'CANCELLED'

interface OrderItem {
  id: string
  quantity: number
  price: number
  product: {
    id: string
    name: string
    price: number
    image: string | null
  }
}

interface CafeteriaOrder {
  id: string
  userId: string
  status: CafeteriaOrderStatus
  total: number
  notes: string | null
  createdAt: Date
  updatedAt: Date
  user: {
    id: string
    name: string | null
    email: string
  }
  items: OrderItem[]
}

const STATUS_CONFIG: Record<CafeteriaOrderStatus, { 
  label: string
  color: string
  icon: React.ElementType
  bgColor: string
}> = {
  PENDING: { 
    label: 'En attente', 
    color: 'text-yellow-500',
    icon: Clock,
    bgColor: 'bg-yellow-500/10',
  },
  CONFIRMED: { 
    label: 'Confirmée', 
    color: 'text-blue-500',
    icon: CheckCircle2,
    bgColor: 'bg-blue-500/10',
  },
  PREPARING: { 
    label: 'En préparation', 
    color: 'text-orange-500',
    icon: ChefHat,
    bgColor: 'bg-orange-500/10',
  },
  READY: { 
    label: 'Prête', 
    color: 'text-green-500',
    icon: Package,
    bgColor: 'bg-green-500/10',
  },
  DELIVERED: { 
    label: 'Récupérée', 
    color: 'text-gray-500',
    icon: CheckCircle2,
    bgColor: 'bg-gray-500/10',
  },
  CANCELLED: { 
    label: 'Annulée', 
    color: 'text-red-500',
    icon: XCircle,
    bgColor: 'bg-red-500/10',
  },
}

interface CafeteriaOrdersTableProps {
  orders: CafeteriaOrder[]
}

export function CafeteriaOrdersTable({ orders }: CafeteriaOrdersTableProps) {
  const router = useRouter()
  const [filter, setFilter] = useState<string>('active')
  const [selectedOrder, setSelectedOrder] = useState<CafeteriaOrder | null>(null)
  const [isUpdating, setIsUpdating] = useState<string | null>(null)

  // Filtrer les commandes
  const filteredOrders = orders.filter(order => {
    if (filter === 'active') {
      return ['PENDING', 'CONFIRMED', 'PREPARING', 'READY'].includes(order.status)
    }
    if (filter === 'all') return true
    return order.status === filter
  })

  // Formater la date/heure
  const formatDateTime = (date: Date) => {
    return new Date(date).toLocaleString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  // Mettre à jour le statut
  const handleStatusChange = async (orderId: string, newStatus: CafeteriaOrderStatus) => {
    setIsUpdating(orderId)
    try {
      const result = await updateCafeteriaOrderStatus(orderId, newStatus)
      if (result.success) {
        toast({
          title: 'Statut mis à jour',
          description: `Commande passée à "${STATUS_CONFIG[newStatus].label}"`,
          variant: 'success',
        })
        router.refresh()
      } else {
        toast({
          title: 'Erreur',
          description: result.error || 'Une erreur est survenue',
          variant: 'destructive',
        })
      }
    } catch (error) {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsUpdating(null)
    }
  }

  // Action rapide: passer au statut suivant
  const getNextStatus = (currentStatus: CafeteriaOrderStatus): CafeteriaOrderStatus | null => {
    const flow: Record<string, CafeteriaOrderStatus> = {
      PENDING: 'CONFIRMED',
      CONFIRMED: 'PREPARING',
      PREPARING: 'READY',
      READY: 'DELIVERED',
    }
    return flow[currentStatus] || null
  }

  return (
    <>
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Commandes</CardTitle>
          <div className="flex items-center gap-2">
            <Select value={filter} onValueChange={setFilter}>
              <SelectTrigger className="w-[180px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="active">Actives</SelectItem>
                <SelectItem value="PENDING">En attente</SelectItem>
                <SelectItem value="CONFIRMED">Confirmées</SelectItem>
                <SelectItem value="PREPARING">En préparation</SelectItem>
                <SelectItem value="READY">Prêtes</SelectItem>
                <SelectItem value="DELIVERED">Récupérées</SelectItem>
                <SelectItem value="CANCELLED">Annulées</SelectItem>
                <SelectItem value="all">Toutes</SelectItem>
              </SelectContent>
            </Select>
            <Button 
              variant="outline" 
              size="icon"
              onClick={() => router.refresh()}
            >
              <RefreshCw className="h-4 w-4" />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {filteredOrders.length > 0 ? (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Commande</TableHead>
                  <TableHead>Client</TableHead>
                  <TableHead>Articles</TableHead>
                  <TableHead>Total</TableHead>
                  <TableHead>Statut</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredOrders.map((order) => {
                  const StatusIcon = STATUS_CONFIG[order.status].icon
                  const nextStatus = getNextStatus(order.status)

                  return (
                    <TableRow key={order.id} className={order.status === 'READY' ? 'bg-green-500/5' : ''}>
                      <TableCell className="font-mono text-sm">
                        #{order.id.slice(-6).toUpperCase()}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <div className="h-8 w-8 rounded-full bg-gradient-to-br from-blue-500 to-violet-500 flex items-center justify-center text-white text-sm font-medium">
                            {order.user.name?.charAt(0) || order.user.email.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <p className="font-medium text-sm">{order.user.name || 'Sans nom'}</p>
                            <p className="text-xs text-muted-foreground">{order.user.email}</p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="text-sm">
                          {order.items.slice(0, 2).map((item, idx) => (
                            <span key={item.id}>
                              {idx > 0 && ', '}
                              {item.quantity}x {item.product.name}
                            </span>
                          ))}
                          {order.items.length > 2 && (
                            <span className="text-muted-foreground"> +{order.items.length - 2}</span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="font-semibold">
                        {formatPrice(order.total)}
                      </TableCell>
                      <TableCell>
                        <Badge 
                          variant="outline" 
                          className={`${STATUS_CONFIG[order.status].color} ${STATUS_CONFIG[order.status].bgColor}`}
                        >
                          <StatusIcon className="h-3 w-3 mr-1" />
                          {STATUS_CONFIG[order.status].label}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {formatDateTime(order.createdAt)}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex items-center justify-end gap-2">
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => setSelectedOrder(order)}
                          >
                            <Eye className="h-4 w-4" />
                          </Button>
                          {nextStatus && (
                            <Button
                              size="sm"
                              disabled={isUpdating === order.id}
                              onClick={() => handleStatusChange(order.id, nextStatus)}
                              className="bg-gradient-to-r from-blue-500 to-violet-500 hover:from-blue-600 hover:to-violet-600"
                            >
                              {isUpdating === order.id ? (
                                <RefreshCw className="h-4 w-4 animate-spin" />
                              ) : (
                                STATUS_CONFIG[nextStatus].label
                              )}
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          ) : (
            <div className="py-12 text-center text-muted-foreground">
              <Package className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p>Aucune commande pour ce filtre</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Modal détails commande */}
      <Dialog open={!!selectedOrder} onOpenChange={() => setSelectedOrder(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              Commande #{selectedOrder?.id.slice(-6).toUpperCase()}
              {selectedOrder && (
                <Badge 
                  variant="outline" 
                  className={`${STATUS_CONFIG[selectedOrder.status].color} ${STATUS_CONFIG[selectedOrder.status].bgColor}`}
                >
                  {STATUS_CONFIG[selectedOrder.status].label}
                </Badge>
              )}
            </DialogTitle>
            <DialogDescription>
              {selectedOrder && formatDateTime(selectedOrder.createdAt)}
            </DialogDescription>
          </DialogHeader>

          {selectedOrder && (
            <div className="space-y-4">
              {/* Client */}
              <div className="flex items-center gap-3 p-3 rounded-lg bg-muted/50">
                <div className="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-violet-500 flex items-center justify-center text-white font-medium">
                  {selectedOrder.user.name?.charAt(0) || selectedOrder.user.email.charAt(0).toUpperCase()}
                </div>
                <div>
                  <p className="font-medium">{selectedOrder.user.name || 'Sans nom'}</p>
                  <p className="text-sm text-muted-foreground">{selectedOrder.user.email}</p>
                </div>
              </div>

              {/* Articles */}
              <div className="space-y-2">
                <h4 className="font-semibold">Articles</h4>
                <div className="space-y-2">
                  {selectedOrder.items.map((item) => (
                    <div key={item.id} className="flex items-center justify-between p-2 rounded-lg border">
                      <div className="flex items-center gap-2">
                        <span className="font-medium">{item.quantity}x</span>
                        <span>{item.product.name}</span>
                      </div>
                      <span className="font-semibold">{formatPrice(item.price * item.quantity)}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Notes */}
              {selectedOrder.notes && (
                <div className="space-y-2">
                  <h4 className="font-semibold">Notes</h4>
                  <p className="text-sm text-muted-foreground p-3 rounded-lg bg-muted/50">
                    {selectedOrder.notes}
                  </p>
                </div>
              )}

              {/* Total */}
              <div className="flex items-center justify-between p-3 rounded-lg bg-gradient-to-r from-blue-500/10 to-violet-500/10 border">
                <span className="font-semibold">Total à payer</span>
                <span className="text-xl font-bold">{formatPrice(selectedOrder.total)}</span>
              </div>

              {/* Actions */}
              <div className="flex gap-2">
                <Select 
                  value={selectedOrder.status}
                  onValueChange={(value) => handleStatusChange(selectedOrder.id, value as CafeteriaOrderStatus)}
                >
                  <SelectTrigger className="flex-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="PENDING">En attente</SelectItem>
                    <SelectItem value="CONFIRMED">Confirmée</SelectItem>
                    <SelectItem value="PREPARING">En préparation</SelectItem>
                    <SelectItem value="READY">Prête</SelectItem>
                    <SelectItem value="DELIVERED">Récupérée</SelectItem>
                    <SelectItem value="CANCELLED">Annulée</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}
