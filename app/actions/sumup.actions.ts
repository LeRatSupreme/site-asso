'use server'

import { requireAdmin } from '@/app/lib/permissions'
import { prisma } from '@/app/lib/prisma'
import { 
  sumupService, 
  type TransactionFilters, 
  type SumUpTransaction, 
  type SumUpFinancialTransaction,
  type PeriodStats,
  type DailyStats 
} from '@/app/lib/sumup'

// Vérifier si SumUp est configuré
export async function isSumUpConfigured() {
  return sumupService.isConfigured()
}

// Récupérer le profil du marchand
export async function getSumUpProfile() {
  try {
    await requireAdmin()
    
    if (!sumupService.isConfigured()) {
      return { success: false, error: 'SumUp non configuré' }
    }

    const profile = await sumupService.getMerchantProfile()
    return { success: true, profile }
  } catch (error) {
    console.error('Get SumUp profile error:', error)
    return { success: false, error: error instanceof Error ? error.message : 'Erreur inconnue' }
  }
}

// Récupérer les transactions
export async function getSumUpTransactions(filters?: TransactionFilters) {
  try {
    await requireAdmin()
    
    if (!sumupService.isConfigured()) {
      return { success: false, error: 'SumUp non configuré', transactions: [] }
    }

    const transactions = await sumupService.getTransactions(filters)
    return { success: true, transactions }
  } catch (error) {
    console.error('Get SumUp transactions error:', error)
    return { success: false, error: error instanceof Error ? error.message : 'Erreur inconnue', transactions: [] }
  }
}

// Récupérer l'historique financier (payouts)
export async function getSumUpFinancials(filters?: TransactionFilters) {
  try {
    await requireAdmin()
    
    if (!sumupService.isConfigured()) {
      return { success: false, error: 'SumUp non configuré', financials: [] }
    }

    const financials = await sumupService.getFinancialTransactions(filters)
    return { success: true, financials }
  } catch (error) {
    console.error('Get SumUp financials error:', error)
    return { success: false, error: error instanceof Error ? error.message : 'Erreur inconnue', financials: [] }
  }
}

// Récupérer les statistiques complètes pour une période
export async function getSumUpStats(startDate: string, endDate: string): Promise<{
  success: boolean
  error?: string
  stats?: PeriodStats
  transactions?: SumUpTransaction[]
}> {
  try {
    await requireAdmin()
    
    if (!sumupService.isConfigured()) {
      return { success: false, error: 'SumUp non configuré' }
    }

    const filters: TransactionFilters = { startDate, endDate }
    
    const transactions = await sumupService.getTransactions(filters)
    const stats = sumupService.calculatePeriodStats(transactions)

    return { 
      success: true, 
      stats, 
      transactions
    }
  } catch (error) {
    console.error('Get SumUp stats error:', error)
    return { success: false, error: error instanceof Error ? error.message : 'Erreur inconnue' }
  }
}

// Générer le CSV pour export
export async function exportSumUpCSV(startDate: string, endDate: string): Promise<{
  success: boolean
  error?: string
  csv?: string
  filename?: string
}> {
  try {
    await requireAdmin()
    
    if (!sumupService.isConfigured()) {
      return { success: false, error: 'SumUp non configuré' }
    }

    const filters: TransactionFilters = { startDate, endDate }
    const transactions = await sumupService.getTransactions(filters)
    const csv = sumupService.generateCSV(transactions)
    const filename = `sumup_export_${startDate}_${endDate}.csv`

    return { success: true, csv, filename }
  } catch (error) {
    console.error('Export SumUp CSV error:', error)
    return { success: false, error: error instanceof Error ? error.message : 'Erreur inconnue' }
  }
}

// Récupérer les statistiques d'aujourd'hui
export async function getSumUpTodayStats() {
  const today = new Date()
  const startDate = today.toISOString().split('T')[0]
  const endDate = startDate

  return getSumUpStats(startDate, endDate)
}

// Récupérer les statistiques de la semaine
export async function getSumUpWeekStats() {
  const today = new Date()
  const weekAgo = new Date(today)
  weekAgo.setDate(weekAgo.getDate() - 7)
  
  const startDate = weekAgo.toISOString().split('T')[0]
  const endDate = today.toISOString().split('T')[0]

  return getSumUpStats(startDate, endDate)
}

// Récupérer les statistiques du mois
export async function getSumUpMonthStats() {
  const today = new Date()
  const monthAgo = new Date(today)
  monthAgo.setMonth(monthAgo.getMonth() - 1)
  
  const startDate = monthAgo.toISOString().split('T')[0]
  const endDate = today.toISOString().split('T')[0]

  return getSumUpStats(startDate, endDate)
}

// Récupérer les statistiques de l'année
export async function getSumUpYearStats() {
  const today = new Date()
  const yearAgo = new Date(today)
  yearAgo.setFullYear(yearAgo.getFullYear() - 1)
  
  const startDate = yearAgo.toISOString().split('T')[0]
  const endDate = today.toISOString().split('T')[0]

  return getSumUpStats(startDate, endDate)
}

// Calculer le bénéfice basé sur les commandes cafétéria
export async function getProfitStats(startDate: string, endDate: string): Promise<{
  success: boolean
  error?: string
  profit?: {
    totalRevenue: number
    totalCost: number
    grossProfit: number
    profitMargin: number
    itemsSold: number
    ordersCount: number
  }
}> {
  try {
    await requireAdmin()

    const start = new Date(startDate)
    start.setHours(0, 0, 0, 0)
    const end = new Date(endDate)
    end.setHours(23, 59, 59, 999)

    // Récupérer les commandes livrées/payées sur la période
    const orders = await prisma.cafeteriaOrder.findMany({
      where: {
        status: 'DELIVERED',
        createdAt: {
          gte: start,
          lte: end,
        },
      },
      include: {
        items: {
          include: {
            product: true,
          },
        },
      },
    })

    let totalRevenue = 0
    let totalCost = 0
    let itemsSold = 0

    for (const order of orders) {
      for (const item of order.items) {
        const revenue = Number(item.price) * item.quantity
        totalRevenue += revenue
        itemsSold += item.quantity

        // Utiliser le costPrice du produit s'il existe
        if (item.product.costPrice) {
          totalCost += Number(item.product.costPrice) * item.quantity
        }
      }
    }

    const grossProfit = totalRevenue - totalCost
    const profitMargin = totalRevenue > 0 ? (grossProfit / totalRevenue) * 100 : 0

    return {
      success: true,
      profit: {
        totalRevenue,
        totalCost,
        grossProfit,
        profitMargin,
        itemsSold,
        ordersCount: orders.length,
      },
    }
  } catch (error) {
    console.error('Get profit stats error:', error)
    return { success: false, error: error instanceof Error ? error.message : 'Erreur inconnue' }
  }
}