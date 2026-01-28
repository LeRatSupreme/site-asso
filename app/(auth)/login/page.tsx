import { Metadata } from 'next'
import { Suspense } from 'react'
import { LoginForm } from './LoginForm'
import { Loader2 } from 'lucide-react'

export const metadata: Metadata = {
  title: 'Connexion',
  description: 'Connectez-vous à votre compte',
}

export default function LoginPage() {
  return (
    <Suspense fallback={
      <div className="flex items-center justify-center min-h-screen">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    }>
      <LoginForm />
    </Suspense>
  )
}
