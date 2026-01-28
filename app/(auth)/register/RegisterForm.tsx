'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import Link from 'next/link'
import { signIn } from 'next-auth/react'
import { Button } from '@/app/components/ui/button'
import { Input } from '@/app/components/ui/input'
import { Label } from '@/app/components/ui/label'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/app/components/ui/card'
import { toast } from '@/app/components/ui/use-toast'
import { Loader2 } from 'lucide-react'
import { registerUser } from '@/app/actions/users.actions'

export function RegisterForm() {
  const router = useRouter()
  const [isLoading, setIsLoading] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    confirmPassword: '',
  })

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData((prev) => ({
      ...prev,
      [e.target.name]: e.target.value,
    }))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    
    if (formData.password !== formData.confirmPassword) {
      toast({
        title: 'Erreur',
        description: 'Les mots de passe ne correspondent pas',
        variant: 'destructive',
      })
      return
    }

    if (formData.password.length < 6) {
      toast({
        title: 'Erreur',
        description: 'Le mot de passe doit contenir au moins 6 caractères',
        variant: 'destructive',
      })
      return
    }

    if (!formData.email.endsWith('@etu.univ-littoral.fr')) {
      toast({
        title: 'Erreur',
        description: 'Seules les adresses email @etu.univ-littoral.fr sont acceptées',
        variant: 'destructive',
      })
      return
    }

    setIsLoading(true)

    try {
      const result = await registerUser({
        name: formData.name,
        email: formData.email,
        password: formData.password,
      })

      if (result.success) {
        toast({
          title: 'Inscription réussie',
          description: 'Votre compte a été créé. Connexion en cours...',
          variant: 'success',
        })

        // Connexion automatique
        const signInResult = await signIn('credentials', {
          email: formData.email,
          password: formData.password,
          redirect: false,
        })

        if (signInResult?.error) {
          router.push('/login')
        } else {
          router.push('/eleve')
          router.refresh()
        }
      } else {
        toast({
          title: 'Erreur',
          description: result.error || 'Une erreur est survenue',
          variant: 'destructive',
        })
      }
    } catch {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <Card className="w-full max-w-md border-0 shadow-2xl shadow-violet-500/10">
      <CardHeader className="space-y-1 pb-6">
        <div className="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-blue-500 flex items-center justify-center mb-4 shadow-lg shadow-violet-500/30">
          <svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
          </svg>
        </div>
        <CardTitle className="text-3xl text-center font-bold">Rejoignez-nous !</CardTitle>
        <CardDescription className="text-center text-base">
          Créez votre compte pour accéder à l&apos;espace membre
        </CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name" className="text-sm font-medium">Nom complet</Label>
            <Input
              id="name"
              name="name"
              type="text"
              placeholder="Jean Dupont"
              value={formData.name}
              onChange={handleChange}
              required
              disabled={isLoading}
              className="h-12 rounded-xl border-2 focus:border-violet-500 transition-colors"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email" className="text-sm font-medium">Email universitaire</Label>
            <Input
              id="email"
              name="email"
              type="email"
              placeholder="prenom.nom@etu.univ-littoral.fr"
              value={formData.email}
              onChange={handleChange}
              required
              disabled={isLoading}
              className="h-12 rounded-xl border-2 focus:border-violet-500 transition-colors"
            />
            <p className="text-xs text-muted-foreground flex items-center gap-1">
              <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
              </svg>
              Utilisez votre adresse @etu.univ-littoral.fr
            </p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="password" className="text-sm font-medium">Mot de passe</Label>
            <Input
              id="password"
              name="password"
              type="password"
              placeholder="••••••••"
              value={formData.password}
              onChange={handleChange}
              required
              disabled={isLoading}
              minLength={6}
              className="h-12 rounded-xl border-2 focus:border-violet-500 transition-colors"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="confirmPassword" className="text-sm font-medium">Confirmer le mot de passe</Label>
            <Input
              id="confirmPassword"
              name="confirmPassword"
              type="password"
              placeholder="••••••••"
              value={formData.confirmPassword}
              onChange={handleChange}
              required
              disabled={isLoading}
              minLength={6}
              className="h-12 rounded-xl border-2 focus:border-violet-500 transition-colors"
            />
          </div>
        </CardContent>
        <CardFooter className="flex flex-col space-y-4 pt-2">
          <Button type="submit" className="w-full h-12 text-base" disabled={isLoading}>
            {isLoading ? (
              <>
                <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                Inscription...
              </>
            ) : (
              'Créer mon compte'
            )}
          </Button>
          <p className="text-sm text-muted-foreground text-center">
            Déjà un compte ?{' '}
            <Link href="/login" className="text-violet-500 hover:text-blue-500 font-medium transition-colors">
              Se connecter
            </Link>
          </p>
        </CardFooter>
      </form>
    </Card>
  )
}
