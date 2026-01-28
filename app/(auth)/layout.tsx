import Link from 'next/link'
import { getSetting } from '@/app/lib/config'

export default async function AuthLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const siteName = await getSetting('site_name')

  return (
    <div className="min-h-screen flex flex-col items-center justify-center relative overflow-hidden">
      {/* Background */}
      <div className="absolute inset-0 bg-gradient-to-br from-blue-50 via-violet-50/50 to-background dark:from-blue-950/30 dark:via-violet-950/20 dark:to-background" />
      
      {/* Animated Blobs */}
      <div className="absolute top-20 -left-20 w-72 h-72 bg-blue-500/20 rounded-full blur-3xl animate-float" />
      <div className="absolute bottom-20 -right-20 w-96 h-96 bg-violet-500/20 rounded-full blur-3xl animate-float animation-delay-400" />
      
      {/* Content */}
      <div className="relative z-10 w-full max-w-md px-4">
        {/* Logo */}
        <Link href="/" className="flex flex-col items-center mb-8 group">
          <div className="h-14 w-14 rounded-2xl bg-gradient-to-br from-blue-500 to-violet-500 flex items-center justify-center shadow-xl shadow-blue-500/30 group-hover:shadow-violet-500/40 transition-all duration-300 mb-4">
            <span className="text-white font-bold text-2xl">
              {(siteName || 'A')[0].toUpperCase()}
            </span>
          </div>
          <h1 className="text-2xl font-bold bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
            {siteName || 'Association'}
          </h1>
        </Link>
        
        {children}
        
        {/* Back to home link */}
        <p className="text-center mt-8 text-sm text-muted-foreground">
          <Link href="/" className="hover:text-blue-500 transition-colors">
            ← Retour à l&apos;accueil
          </Link>
        </p>
      </div>
    </div>
  )
}
