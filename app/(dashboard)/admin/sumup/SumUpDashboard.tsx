'use client'

import { useState, useEffect, useCallback } from 'react'
import { 
  CreditCard, 
  TrendingUp, 
  TrendingDown,
  Download,
  Calendar,
  Euro,
  ArrowUpRight,
  ArrowDownRight,
  CheckCircle2,
  XCircle,
  Clock,
  Loader2,
  RefreshCw,
  Filter,
  ChevronDown,
  Wallet,
  ShoppingBag,
} from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Badge } from '@/app/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/app/components/ui/tabs'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/app/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/app/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/app/components/ui/dropdown-menu'
import { Input } from '@/app/components/ui/input'
import { Label } from '@/app/components/ui/label'
import { toast } from '@/app/components/ui/use-toast'
import { formatPrice } from '@/app/lib/utils'
import { 
  getSumUpStats, 
  exportSumUpCSV,
  getProfitStats,
} from '@/app/actions/sumup.actions'
import type { SumUpTransaction, PeriodStats, DailyStats } from '@/app/lib/sumup'

type Period = 'today' | 'week' | 'month' | 'year' | 'custom'

interface ProfitData {
  totalRevenue: number
  totalCost: number
  grossProfit: number
  profitMargin: number
  itemsSold: number
  ordersCount: number
}

const STATUS_CONFIG = {
  SUCCESSFUL: { label: 'Réussi', color: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', icon: CheckCircle2 },
  FAILED: { label: 'Échoué', color: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', icon: XCircle },
  CANCELLED: { label: 'Annulé', color: 'bg-gray-100 text-gray-700 dark:bg-gray-800/50 dark:text-gray-400', icon: XCircle },
  PENDING: { label: 'En attente', color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', icon: Clock },
}

function getDateRange(period: Period): { startDate: string; endDate: string } {
  const today = new Date()
  const endDate = today.toISOString().split('T')[0]
  
  switch (period) {
    case 'today':
      return { startDate: endDate, endDate }
    case 'week': {
      const weekAgo = new Date(today)
      weekAgo.setDate(weekAgo.getDate() - 7)
      return { startDate: weekAgo.toISOString().split('T')[0], endDate }
    }
    case 'month': {
      const monthAgo = new Date(today)
      monthAgo.setMonth(monthAgo.getMonth() - 1)
      return { startDate: monthAgo.toISOString().split('T')[0], endDate }
    }
    case 'year': {
      const yearAgo = new Date(today)
      yearAgo.setFullYear(yearAgo.getFullYear() - 1)
      return { startDate: yearAgo.toISOString().split('T')[0], endDate }
    }
    default:
      return { startDate: endDate, endDate }
  }
}

function StatCard({ 
  title, 
  value, 
  description, 
  icon: Icon, 
  trend,
  trendValue,
}: { 
  title: string
  value: string
  description?: string
  icon: React.ElementType
  trend?: 'up' | 'down' | 'neutral'
  trendValue?: string
}) {
  return (
    <Card>
      <CardContent className="p-6">
        <div className="flex items-start justify-between">
          <div>
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="text-2xl font-bold mt-1">{value}</p>
            {description && (
              <p className="text-xs text-muted-foreground mt-1">{description}</p>
            )}
          </div>
          <div className="flex flex-col items-end gap-2">
            <div className="p-2 rounded-lg bg-primary/10">
              <Icon className="h-5 w-5 text-primary" />
            </div>
            {trend && trendValue && (
              <div className={`flex items-center text-xs font-medium ${
                trend === 'up' ? 'text-green-600' : trend === 'down' ? 'text-red-600' : 'text-muted-foreground'
              }`}>
                {trend === 'up' ? <ArrowUpRight className="h-3 w-3" /> : trend === 'down' ? <ArrowDownRight className="h-3 w-3" /> : null}
                {trendValue}
              </div>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function SimpleBarChart({ data }: { data: DailyStats[] }) {
  if (data.length === 0) return null
  
  const maxAmount = Math.max(...data.map(d => d.totalAmount), 1)
  
  return (
    <div className="h-64 flex items-end gap-1 sm:gap-2 px-2">
      {data.map((day, index) => {
        const height = (day.totalAmount / maxAmount) * 100
        const date = new Date(day.date)
        const dayLabel = date.toLocaleDateString('fr-FR', { weekday: 'short' })
        const dateLabel = date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
        
        return (
          <div key={day.date} className="flex-1 flex flex-col items-center gap-1 min-w-0">
            <div className="w-full flex flex-col items-center">
              <span className="text-xs text-muted-foreground mb-1 hidden sm:block">
                {formatPrice(day.totalAmount)}
              </span>
              <div 
                className="w-full bg-gradient-to-t from-blue-600 to-violet-500 rounded-t-sm transition-all hover:from-blue-500 hover:to-violet-400 cursor-pointer relative group"
                style={{ height: `${Math.max(height, 4)}%` }}
              >
                <div className="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 hidden group-hover:block z-10">
                  <div className="bg-popover border rounded-lg shadow-lg p-2 text-xs whitespace-nowrap">
                    <p className="font-medium">{formatPrice(day.totalAmount)}</p>
                    <p className="text-muted-foreground">{day.transactionCount} transactions</p>
                  </div>
                </div>
              </div>
            </div>
            <div className="text-center">
              <p className="text-xs font-medium truncate">{dayLabel}</p>
              <p className="text-xs text-muted-foreground hidden sm:block">{dateLabel}</p>
            </div>
          </div>
        )
      })}
    </div>
  )
}

export function SumUpDashboard() {
  const [period, setPeriod] = useState<Period>('week')
  const [customStartDate, setCustomStartDate] = useState('')
  const [customEndDate, setCustomEndDate] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isExporting, setIsExporting] = useState(false)
  const [stats, setStats] = useState<PeriodStats | null>(null)
  const [transactions, setTransactions] = useState<SumUpTransaction[]>([])
  const [profitData, setProfitData] = useState<ProfitData | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadData = useCallback(async () => {
    setIsLoading(true)
    setError(null)

    try {
      let startDate: string, endDate: string

      if (period === 'custom' && customStartDate && customEndDate) {
        startDate = customStartDate
        endDate = customEndDate
      } else {
        const range = getDateRange(period)
        startDate = range.startDate
        endDate = range.endDate
      }

      // Charger les stats SumUp et les stats de bénéfice en parallèle
      const [sumupResult, profitResult] = await Promise.all([
        getSumUpStats(startDate, endDate),
        getProfitStats(startDate, endDate),
      ])

      if (sumupResult.success && sumupResult.stats) {
        setStats(sumupResult.stats)
        setTransactions(sumupResult.transactions || [])
      } else {
        setError(sumupResult.error || 'Erreur inconnue')
      }

      if (profitResult.success && profitResult.profit) {
        setProfitData(profitResult.profit)
      }
    } catch (err) {
      setError('Erreur lors du chargement des données')
    } finally {
      setIsLoading(false)
    }
  }, [period, customStartDate, customEndDate])

  useEffect(() => {
    if (period !== 'custom' || (customStartDate && customEndDate)) {
      loadData()
    }
  }, [period, loadData])

  const handleExportCSV = async () => {
    setIsExporting(true)
    try {
      let startDate: string, endDate: string

      if (period === 'custom' && customStartDate && customEndDate) {
        startDate = customStartDate
        endDate = customEndDate
      } else {
        const range = getDateRange(period)
        startDate = range.startDate
        endDate = range.endDate
      }

      const result = await exportSumUpCSV(startDate, endDate)

      if (result.success && result.csv && result.filename) {
        // Créer et télécharger le fichier
        const blob = new Blob([result.csv], { type: 'text/csv;charset=utf-8;' })
        const link = document.createElement('a')
        link.href = URL.createObjectURL(blob)
        link.download = result.filename
        link.click()
        URL.revokeObjectURL(link.href)

        toast({
          title: 'Export réussi',
          description: `Le fichier ${result.filename} a été téléchargé`,
        })
      } else {
        toast({
          title: 'Erreur',
          description: result.error || 'Erreur lors de l\'export',
          variant: 'destructive',
        })
      }
    } catch {
      toast({
        title: 'Erreur',
        description: 'Erreur lors de l\'export',
        variant: 'destructive',
      })
    } finally {
      setIsExporting(false)
    }
  }

  const formatDateTime = (timestamp: string) => {
    const date = new Date(timestamp)
    return {
      date: date.toLocaleDateString('fr-FR'),
      time: date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }),
    }
  }

  return (
    <div className="container max-w-7xl mx-auto px-4 py-6 space-y-6">
      {/* Header */}
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-violet-600 to-purple-600 p-6 sm:p-8 text-white">
        <div className="absolute top-0 right-0 -mt-8 -mr-8 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
        <div className="absolute bottom-0 left-0 -mb-8 -ml-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
        <div className="relative">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <div className="p-2 rounded-xl bg-white/20 backdrop-blur-sm">
                  <CreditCard className="h-6 w-6" />
                </div>
                <h1 className="text-2xl sm:text-3xl font-bold">SumUp Paiements</h1>
              </div>
              <p className="text-white/80">
                Statistiques et historique des transactions
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                className="bg-white/20 hover:bg-white/30 text-white border-0"
                onClick={loadData}
                disabled={isLoading}
              >
                <RefreshCw className={`h-4 w-4 mr-2 ${isLoading ? 'animate-spin' : ''}`} />
                Actualiser
              </Button>
              <Button
                className="bg-white/95 text-violet-700 hover:bg-white shadow-lg font-semibold border border-white/50"
                onClick={handleExportCSV}
                disabled={isExporting || isLoading}
              >
                {isExporting ? (
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Download className="h-4 w-4 mr-2" />
                )}
                Exporter CSV
              </Button>
            </div>
          </div>

          {/* Filtres période */}
          <div className="flex flex-wrap items-center gap-3 mt-6 p-4 rounded-xl bg-white/10 backdrop-blur-sm">
            <div className="flex items-center gap-2">
              <Calendar className="h-4 w-4" />
              <span className="text-sm font-medium">Période :</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {(['today', 'week', 'month', 'year', 'custom'] as Period[]).map((p) => (
                <Button
                  key={p}
                  size="sm"
                  variant={period === p ? 'secondary' : 'ghost'}
                  className={period === p ? 'bg-white text-violet-700' : 'text-white hover:bg-white/20'}
                  onClick={() => setPeriod(p)}
                >
                  {p === 'today' ? "Aujourd'hui" : 
                   p === 'week' ? 'Semaine' : 
                   p === 'month' ? 'Mois' : 
                   p === 'year' ? 'Année' : 'Personnalisé'}
                </Button>
              ))}
            </div>
            {period === 'custom' && (
              <div className="flex items-center gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                <Input
                  type="date"
                  value={customStartDate}
                  onChange={(e) => setCustomStartDate(e.target.value)}
                  className="bg-white/20 border-white/30 text-white placeholder:text-white/60"
                />
                <span>→</span>
                <Input
                  type="date"
                  value={customEndDate}
                  onChange={(e) => setCustomEndDate(e.target.value)}
                  className="bg-white/20 border-white/30 text-white placeholder:text-white/60"
                />
                <Button
                  size="sm"
                  variant="secondary"
                  className="bg-white text-violet-700"
                  onClick={loadData}
                  disabled={!customStartDate || !customEndDate}
                >
                  Appliquer
                </Button>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Erreur */}
      {error && (
        <Card className="border-red-500/50 bg-red-50/50 dark:bg-red-950/20">
          <CardContent className="p-4 text-center text-red-600 dark:text-red-400">
            {error}
          </CardContent>
        </Card>
      )}

      {/* Loading */}
      {isLoading && (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      )}

      {/* Stats */}
      {!isLoading && stats && (
        <>
          {/* Cartes de statistiques */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              title="Chiffre d'affaires"
              value={formatPrice(stats.totalRevenue)}
              description={`${stats.successfulTransactions} transactions réussies`}
              icon={Euro}
              trend="up"
            />
            <StatCard
              title="Revenu net"
              value={formatPrice(stats.netRevenue)}
              description={`Après frais (${formatPrice(stats.totalFees)})`}
              icon={TrendingUp}
            />
            <StatCard
              title="Panier moyen"
              value={formatPrice(stats.avgTransactionAmount)}
              description="Par transaction"
              icon={CreditCard}
            />
            <StatCard
              title="Taux de succès"
              value={`${stats.totalTransactions > 0 ? Math.round((stats.successfulTransactions / stats.totalTransactions) * 100) : 0}%`}
              description={`${stats.failedTransactions} échecs`}
              icon={stats.failedTransactions > 0 ? TrendingDown : TrendingUp}
              trend={stats.failedTransactions > 0 ? 'down' : 'up'}
            />
          </div>

          {/* Cartes de bénéfices */}
          {profitData && (
            <Card className="border-green-200 dark:border-green-800 bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-950/50 dark:to-emerald-950/50">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-green-800 dark:text-green-200">
                  <Wallet className="h-5 w-5" />
                  Bénéfices (Cafétéria)
                </CardTitle>
                <CardDescription>
                  Calculé à partir des commandes payées sur la période
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                  <div className="space-y-1">
                    <p className="text-sm text-muted-foreground">Bénéfice net</p>
                    <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                      {formatPrice(profitData.grossProfit)}
                    </p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-sm text-muted-foreground">Marge bénéficiaire</p>
                    <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                      {profitData.profitMargin.toFixed(1)}%
                    </p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-sm text-muted-foreground">Coût total</p>
                    <p className="text-2xl font-bold text-orange-600 dark:text-orange-400">
                      {formatPrice(profitData.totalCost)}
                    </p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-sm text-muted-foreground">Articles vendus</p>
                    <p className="text-2xl font-bold flex items-center gap-1">
                      <ShoppingBag className="h-5 w-5 text-muted-foreground" />
                      {profitData.itemsSold}
                    </p>
                    <p className="text-xs text-muted-foreground">{profitData.ordersCount} commandes</p>
                  </div>
                </div>
                {profitData.totalRevenue > 0 && (
                  <div className="mt-4 pt-4 border-t border-green-200 dark:border-green-800">
                    <div className="flex justify-between items-center text-sm">
                      <span className="text-muted-foreground">Chiffre d&apos;affaires cafétéria</span>
                      <span className="font-medium">{formatPrice(profitData.totalRevenue)}</span>
                    </div>
                    <div className="flex justify-between items-center text-sm mt-1">
                      <span className="text-muted-foreground">Coût des marchandises</span>
                      <span className="font-medium text-orange-600">-{formatPrice(profitData.totalCost)}</span>
                    </div>
                    <div className="flex justify-between items-center text-sm mt-1 pt-1 border-t border-green-200 dark:border-green-800">
                      <span className="font-medium">Bénéfice net</span>
                      <span className="font-bold text-green-600">{formatPrice(profitData.grossProfit)}</span>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>
          )}

          {/* Graphique */}
          {stats.dailyBreakdown.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Évolution du chiffre d&apos;affaires</CardTitle>
                <CardDescription>
                  Répartition journalière sur la période sélectionnée
                </CardDescription>
              </CardHeader>
              <CardContent>
                <SimpleBarChart data={stats.dailyBreakdown} />
              </CardContent>
            </Card>
          )}

          {/* Tableau des transactions */}
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Transactions récentes</CardTitle>
                  <CardDescription>
                    {transactions.length} transaction{transactions.length > 1 ? 's' : ''} sur la période
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {transactions.length > 0 ? (
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Code</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Statut</TableHead>
                        <TableHead>Carte</TableHead>
                        <TableHead className="text-right">Montant</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {transactions.slice(0, 50).map((transaction) => {
                        const { date, time } = formatDateTime(transaction.timestamp)
                        const statusConfig = STATUS_CONFIG[transaction.status as keyof typeof STATUS_CONFIG] || STATUS_CONFIG.PENDING
                        const StatusIcon = statusConfig.icon

                        return (
                          <TableRow key={transaction.id}>
                            <TableCell>
                              <div>
                                <p className="font-medium">{date}</p>
                                <p className="text-xs text-muted-foreground">{time}</p>
                              </div>
                            </TableCell>
                            <TableCell>
                              <code className="text-xs bg-muted px-2 py-1 rounded">
                                {transaction.transaction_code}
                              </code>
                            </TableCell>
                            <TableCell>
                              <Badge variant="outline">
                                {transaction.payment_type}
                              </Badge>
                            </TableCell>
                            <TableCell>
                              <Badge className={statusConfig.color}>
                                <StatusIcon className="h-3 w-3 mr-1" />
                                {statusConfig.label}
                              </Badge>
                            </TableCell>
                            <TableCell>
                              {transaction.card ? (
                                <span className="text-sm">
                                  {transaction.card.type} ****{transaction.card.last_4_digits}
                                </span>
                              ) : (
                                <span className="text-muted-foreground">-</span>
                              )}
                            </TableCell>
                            <TableCell className="text-right">
                              <span className={`font-semibold ${
                                transaction.status === 'SUCCESSFUL' ? 'text-green-600' : 
                                transaction.status === 'FAILED' || transaction.status === 'CANCELLED' ? 'text-red-600' : ''
                              }`}>
                                {formatPrice(transaction.amount)}
                              </span>
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <div className="text-center py-12 text-muted-foreground">
                  <CreditCard className="h-12 w-12 mx-auto mb-4 opacity-50" />
                  <p>Aucune transaction sur cette période</p>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Résumé journalier */}
          {stats.dailyBreakdown.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Détail par jour</CardTitle>
                <CardDescription>
                  Résumé des transactions jour par jour
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead className="text-center">Transactions</TableHead>
                        <TableHead className="text-center">Réussies</TableHead>
                        <TableHead className="text-center">Échouées</TableHead>
                        <TableHead className="text-right">CA</TableHead>
                        <TableHead className="text-right">Frais</TableHead>
                        <TableHead className="text-right">Moyenne</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {stats.dailyBreakdown.map((day) => (
                        <TableRow key={day.date}>
                          <TableCell>
                            <span className="font-medium">
                              {new Date(day.date).toLocaleDateString('fr-FR', { 
                                weekday: 'short', 
                                day: 'numeric', 
                                month: 'short' 
                              })}
                            </span>
                          </TableCell>
                          <TableCell className="text-center">{day.transactionCount}</TableCell>
                          <TableCell className="text-center text-green-600">{day.successfulCount}</TableCell>
                          <TableCell className="text-center text-red-600">{day.failedCount}</TableCell>
                          <TableCell className="text-right font-semibold">{formatPrice(day.totalAmount)}</TableCell>
                          <TableCell className="text-right text-muted-foreground">{formatPrice(day.fees)}</TableCell>
                          <TableCell className="text-right">{formatPrice(day.avgTransaction)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
