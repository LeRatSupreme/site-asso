import { Metadata } from 'next'
import { redirect } from 'next/navigation'
import { auth } from '@/app/lib/auth'
import { isSumUpConfigured } from '@/app/actions/sumup.actions'
import { SumUpDashboard } from './SumUpDashboard'
import { Card, CardContent } from '@/app/components/ui/card'
import { AlertTriangle, CreditCard, Settings } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import Link from 'next/link'

export const metadata: Metadata = {
  title: 'SumUp - Paiements',
  description: 'Tableau de bord des paiements SumUp',
}

export default async function SumUpPage() {
  const session = await auth()
  if (!session?.user || session.user.role !== 'ADMIN') {
    redirect('/unauthorized')
  }

  const isConfigured = await isSumUpConfigured()

  if (!isConfigured) {
    return (
      <div className="container max-w-5xl mx-auto px-4 py-6 space-y-8">
        {/* Header */}
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-violet-600 to-purple-600 p-8 text-white">
          <div className="absolute top-0 right-0 -mt-8 -mr-8 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
          <div className="absolute bottom-0 left-0 -mb-8 -ml-8 h-32 w-32 rounded-full bg-white/10 blur-2xl" />
          <div className="relative">
            <div className="flex items-center gap-3 mb-2">
              <div className="p-2 rounded-xl bg-white/20 backdrop-blur-sm">
                <CreditCard className="h-6 w-6" />
              </div>
              <h1 className="text-2xl sm:text-3xl font-bold">SumUp Paiements</h1>
            </div>
            <p className="text-white/80">
              Gérez vos paiements et consultez vos statistiques
            </p>
          </div>
        </div>

        <Card className="border-yellow-500/50 bg-yellow-50/50 dark:bg-yellow-950/20">
          <CardContent className="py-12 text-center">
            <div className="inline-flex h-16 w-16 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/30 mb-6">
              <AlertTriangle className="h-8 w-8 text-yellow-600 dark:text-yellow-500" />
            </div>
            <h3 className="text-xl font-semibold mb-2">Configuration requise</h3>
            <p className="text-muted-foreground mb-6 max-w-md mx-auto">
              L&apos;API SumUp n&apos;est pas configurée. Ajoutez vos clés API dans le fichier .env pour activer cette fonctionnalité.
            </p>
            <div className="bg-muted rounded-lg p-4 max-w-md mx-auto text-left mb-6">
              <p className="text-sm font-mono text-muted-foreground">
                # Dans votre fichier .env<br />
                SUMUP_API_KEY=&quot;votre_api_key&quot;<br />
                SUMUP_MERCHANT_CODE=&quot;votre_merchant_code&quot;
              </p>
            </div>
            <div className="flex flex-col sm:flex-row gap-3 justify-center">
              <Button asChild variant="outline">
                <Link href="https://developer.sumup.com/" target="_blank">
                  Documentation SumUp
                </Link>
              </Button>
              <Button asChild>
                <Link href="/admin/settings">
                  <Settings className="mr-2 h-4 w-4" />
                  Paramètres
                </Link>
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  return <SumUpDashboard />
}
