import Link from 'next/link'
import { ShieldX } from 'lucide-react'
import { Button } from '@/app/components/ui/button'

export default function UnauthorizedPage() {
  return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="text-center">
        <ShieldX className="h-16 w-16 text-destructive mx-auto mb-4" />
        <h1 className="text-2xl font-bold mb-2">Accès refusé</h1>
        <p className="text-muted-foreground mb-6">
          Vous n&apos;avez pas les permissions nécessaires pour accéder à cette page.
        </p>
        <div className="flex gap-4 justify-center">
          <Button asChild variant="outline">
            <Link href="/">Retour à l&apos;accueil</Link>
          </Button>
          <Button asChild>
            <Link href="/login">Se connecter</Link>
          </Button>
        </div>
      </div>
    </div>
  )
}
