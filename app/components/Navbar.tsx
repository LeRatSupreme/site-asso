import Link from 'next/link'
import { getSetting } from '@/app/lib/config'
import { getAuthSession } from '@/app/lib/permissions'
import { Button } from '@/app/components/ui/button'
import { UserNav } from './UserNav'
import { ThemeToggle } from './ThemeToggle'
import { MobileNav } from './MobileNav'

export async function Navbar() {
  let siteName = 'Association'
  let session = null
  
  try {
    siteName = await getSetting('site_name') || 'Association'
    session = await getAuthSession()
  } catch {
    // Fallback pendant le build statique
  }

  const navLinks = [
    { href: '/', label: 'Accueil' },
    { href: '/events', label: 'Événements' },
    { href: '/presentation', label: 'Présentation' },
    { href: '/team', label: 'Équipe' },
  ]

  return (
    <nav className="sticky top-0 z-50 w-full border-b border-border/40 bg-background/80 backdrop-blur-xl supports-[backdrop-filter]:bg-background/60">
      <div className="container flex h-16 items-center justify-between">
        <div className="flex items-center gap-8">
          <Link href="/" className="flex items-center space-x-2 group">
            <div className="h-9 w-9 rounded-xl bg-gradient-to-br from-blue-500 to-violet-500 flex items-center justify-center shadow-lg shadow-blue-500/20 group-hover:shadow-violet-500/30 transition-all duration-300">
              <span className="text-white font-bold text-lg">
                {(siteName || 'A')[0].toUpperCase()}
              </span>
            </div>
            <span className="font-bold text-xl bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent hidden sm:inline-block">
              {siteName || 'Association'}
            </span>
          </Link>
          
          <div className="hidden md:flex items-center gap-1">
            {navLinks.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="px-4 py-2 text-sm font-medium text-muted-foreground rounded-lg transition-all duration-200 hover:text-foreground hover:bg-blue-50 dark:hover:bg-blue-950/50 relative group"
              >
                {link.label}
                <span className="absolute bottom-0 left-1/2 -translate-x-1/2 w-0 h-0.5 bg-gradient-to-r from-blue-500 to-violet-500 rounded-full transition-all duration-300 group-hover:w-1/2" />
              </Link>
            ))}
          </div>
        </div>

        <div className="flex items-center gap-3">
          <ThemeToggle />
          
          {session?.user ? (
            <UserNav user={session.user} />
          ) : (
            <div className="hidden sm:flex items-center gap-2">
              <Button variant="ghost" asChild size="sm">
                <Link href="/login">Connexion</Link>
              </Button>
              <Button asChild size="sm">
                <Link href="/register">S&apos;inscrire</Link>
              </Button>
            </div>
          )}
          
          <MobileNav navLinks={navLinks} isLoggedIn={!!session?.user} />
        </div>
      </div>
    </nav>
  )
}
