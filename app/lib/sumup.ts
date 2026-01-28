// Service API SumUp
// Documentation: https://developer.sumup.com/api

const SUMUP_API_URL = 'https://api.sumup.com'

export interface SumUpTransaction {
  id: string
  transaction_code: string
  amount: number
  currency: string
  timestamp: string
  status: 'SUCCESSFUL' | 'CANCELLED' | 'FAILED' | 'PENDING'
  payment_type: string
  card?: {
    last_4_digits: string
    type: string
  }
  product_summary?: string
  installments_count?: number
  payout_plan?: string
  merchant_code: string
  tip_amount?: number
  type?: string
}

interface SumUpTransactionsResponse {
  items: SumUpTransaction[]
  links?: {
    self?: string
    next?: string
  }[]
}

export interface SumUpFinancialTransaction {
  id: number
  transaction_id: string
  transaction_code: string
  type: 'PAYMENT' | 'REFUND' | 'CHARGE_BACK' | 'PAYOUT'
  status: string
  amount: number
  fee_amount: number
  installment_number?: number
  currency: string
  timestamp: string
  payout_date?: string
  product_summary?: string
}

interface SumUpFinancialsResponse {
  items: SumUpFinancialTransaction[]
  links?: {
    self?: string
    next?: string
  }[]
}

interface SumUpPayout {
  id: number
  amount: number
  currency: string
  date: string
  fee: number
  reference: string
  status: string
  transaction_code: string
  type: string
}

interface SumUpMerchantProfile {
  merchant_code: string
  company_name: string
  legal_type: {
    id: number
    description: string
  }
  country: string
  locale: string
  currency: string
  address: {
    city: string
    country: string
    line1: string
    postal_code: string
  }
}

export interface TransactionFilters {
  startDate?: string
  endDate?: string
  status?: string[]
  paymentType?: string[]
  limit?: number
}

export interface DailyStats {
  date: string
  totalAmount: number
  transactionCount: number
  successfulCount: number
  failedCount: number
  avgTransaction: number
  fees: number
}

export interface PeriodStats {
  totalRevenue: number
  totalTransactions: number
  successfulTransactions: number
  failedTransactions: number
  avgTransactionAmount: number
  totalFees: number
  netRevenue: number
  dailyBreakdown: DailyStats[]
}

class SumUpService {
  private apiKey: string
  private merchantCode: string

  constructor() {
    this.apiKey = process.env.SUMUP_API_KEY || ''
    this.merchantCode = process.env.SUMUP_MERCHANT_CODE || ''
  }

  private async fetch<T>(endpoint: string, options?: RequestInit): Promise<T> {
    if (!this.apiKey) {
      throw new Error('SUMUP_API_KEY non configurée')
    }

    const response = await fetch(`${SUMUP_API_URL}${endpoint}`, {
      ...options,
      headers: {
        'Authorization': `Bearer ${this.apiKey}`,
        'Content-Type': 'application/json',
        ...options?.headers,
      },
    })

    if (!response.ok) {
      const error = await response.text()
      console.error(`SumUp API Error: ${response.status} - ${error}`)
      throw new Error(`SumUp API Error: ${response.status} - ${error}`)
    }

    return response.json()
  }

  // Récupérer le profil du marchand
  async getMerchantProfile(): Promise<SumUpMerchantProfile> {
    return this.fetch<SumUpMerchantProfile>('/v0.1/me')
  }

  // Récupérer les transactions - utilise le nouvel endpoint v2.1
  async getTransactions(filters?: TransactionFilters): Promise<SumUpTransaction[]> {
    const params = new URLSearchParams()
    
    if (filters?.startDate) {
      // Format ISO8601 avec timezone
      params.append('oldest_time', `${filters.startDate}T00:00:00Z`)
    }
    if (filters?.endDate) {
      params.append('newest_time', `${filters.endDate}T23:59:59Z`)
    }
    if (filters?.status?.length) {
      filters.status.forEach(s => params.append('statuses', s))
    }
    if (filters?.paymentType?.length) {
      filters.paymentType.forEach(p => params.append('payment_types', p))
    }
    if (filters?.limit) {
      params.append('limit', filters.limit.toString())
    } else {
      params.append('limit', '1000') // Par défaut, récupérer plus de transactions
    }

    const queryString = params.toString()
    
    // Essayer d'abord le nouvel endpoint v2.1, puis fallback sur v0.1
    try {
      const endpoint = `/v2.1/merchants/${this.merchantCode}/transactions/history${queryString ? `?${queryString}` : ''}`
      const response = await this.fetch<SumUpTransactionsResponse>(endpoint)
      return response.items || []
    } catch (error) {
      console.log('Trying fallback endpoint v0.1/me/transactions/history')
      // Fallback sur l'ancien endpoint
      try {
        const endpoint = `/v0.1/me/transactions/history${queryString ? `?${queryString}` : ''}`
        const response = await this.fetch<SumUpTransactionsResponse>(endpoint)
        return response.items || []
      } catch (fallbackError) {
        console.error('Both endpoints failed:', fallbackError)
        return []
      }
    }
  }

  // Récupérer l'historique financier (payouts)
  async getFinancialTransactions(filters?: TransactionFilters): Promise<SumUpPayout[]> {
    if (!filters?.startDate || !filters?.endDate) {
      return []
    }
    
    const params = new URLSearchParams()
    params.append('start_date', filters.startDate)
    params.append('end_date', filters.endDate)
    params.append('format', 'json')
    if (filters?.limit) {
      params.append('limit', filters.limit.toString())
    }

    const queryString = params.toString()
    
    // Essayer d'abord le nouvel endpoint v1.0, puis fallback sur v0.1
    try {
      const endpoint = `/v1.0/merchants/${this.merchantCode}/payouts${queryString ? `?${queryString}` : ''}`
      const response = await this.fetch<SumUpPayout[]>(endpoint)
      return response || []
    } catch (error) {
      console.log('Trying fallback endpoint v0.1/me/financials/payouts')
      try {
        const endpoint = `/v0.1/me/financials/payouts${queryString ? `?${queryString}` : ''}`
        const response = await this.fetch<SumUpPayout[]>(endpoint)
        return response || []
      } catch (fallbackError) {
        console.error('Both payout endpoints failed:', fallbackError)
        return []
      }
    }
  }

  // Calculer les statistiques pour une période
  calculatePeriodStats(transactions: SumUpTransaction[]): PeriodStats {
    const successfulTransactions = transactions.filter(t => t.status === 'SUCCESSFUL')
    const failedTransactions = transactions.filter(t => t.status === 'FAILED' || t.status === 'CANCELLED')
    
    const totalRevenue = successfulTransactions.reduce((sum, t) => sum + t.amount, 0)
    // Estimation des frais SumUp (~1.75% en moyenne)
    const estimatedFeeRate = 0.0175
    const totalFees = totalRevenue * estimatedFeeRate

    // Calculer le breakdown journalier
    const dailyMap = new Map<string, DailyStats>()
    
    transactions.forEach(t => {
      const date = t.timestamp.split('T')[0]
      const existing = dailyMap.get(date) || {
        date,
        totalAmount: 0,
        transactionCount: 0,
        successfulCount: 0,
        failedCount: 0,
        avgTransaction: 0,
        fees: 0,
      }
      
      existing.transactionCount++
      if (t.status === 'SUCCESSFUL') {
        existing.totalAmount += t.amount
        existing.successfulCount++
        existing.fees += t.amount * estimatedFeeRate
      } else if (t.status === 'FAILED' || t.status === 'CANCELLED') {
        existing.failedCount++
      }
      
      dailyMap.set(date, existing)
    })

    // Calculer la moyenne par jour
    dailyMap.forEach(day => {
      day.avgTransaction = day.successfulCount > 0 ? day.totalAmount / day.successfulCount : 0
    })

    const dailyBreakdown = Array.from(dailyMap.values()).sort((a, b) => 
      new Date(a.date).getTime() - new Date(b.date).getTime()
    )

    return {
      totalRevenue,
      totalTransactions: transactions.length,
      successfulTransactions: successfulTransactions.length,
      failedTransactions: failedTransactions.length,
      avgTransactionAmount: successfulTransactions.length > 0 ? totalRevenue / successfulTransactions.length : 0,
      totalFees,
      netRevenue: totalRevenue - totalFees,
      dailyBreakdown,
    }
  }

  // Générer le CSV du journal de compte
  generateCSV(transactions: SumUpTransaction[]): string {
    const headers = [
      'Date',
      'Heure',
      'Code Transaction',
      'Type',
      'Statut',
      'Montant',
      'Devise',
      'Mode de paiement',
      'Carte',
      'Description',
    ].join(';')

    const rows = transactions.map(t => {
      const dateTime = new Date(t.timestamp)
      const date = dateTime.toLocaleDateString('fr-FR')
      const time = dateTime.toLocaleTimeString('fr-FR')

      return [
        date,
        time,
        t.transaction_code,
        t.type || t.payment_type,
        t.status,
        t.amount.toFixed(2).replace('.', ','),
        t.currency,
        t.payment_type,
        t.card ? `****${t.card.last_4_digits}` : '',
        t.product_summary || '',
      ].join(';')
    })

    return [headers, ...rows].join('\n')
  }

  // Vérifier si l'API est configurée
  isConfigured(): boolean {
    return !!(this.apiKey && this.merchantCode)
  }
}

export const sumupService = new SumUpService()
export type { SumUpMerchantProfile }
