import Link from 'next/link'
import { getSetting } from '@/app/lib/config'
import { getAuthSession } from '@/app/lib/permissions'
import { Button } from '@/app/components/ui/button'
import { UserNav } from './UserNav'
import { MobileNav } from './MobileNav'
import { NavLinks } from './NavLinks'

export async function Navbar() {
  let siteName = 'AEIC'
  let session = null

  try {
    siteName = await getSetting('site_name') || 'AEIC'
    session = await getAuthSession()
  } catch {}

  const navLinks = [
    { href: '/', label: 'Accueil' },
    { href: '/events', label: 'Événements' },
    { href: '/presentation', label: 'L’association' },
    { href: '/team', label: 'Équipe' },
  ]

  return (
    <>
      <header className="sticky top-0 z-40 border-b border-white/[0.07] bg-[#08172d]/90 backdrop-blur-xl">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 lg:px-8">
          <Link href="/" className="flex items-center gap-2.5" aria-label={siteName}>
            <div className="grid size-11 shrink-0 place-items-center rounded-full border border-white/15 bg-gradient-to-br from-violet-500 to-teal-400 text-xs font-black tracking-tight text-white shadow-glow">
              AE
            </div>
            <div className="flex flex-col gap-[1px]">
              <span className="text-[8.5px] font-bold uppercase tracking-[0.28em] text-primary">
                Étudiants · Calais
              </span>
              <span className="text-[15px] font-black leading-none tracking-[-0.04em] text-white">
                {siteName.toUpperCase()}
              </span>
            </div>
          </Link>

          <NavLinks links={navLinks} />

          <div className="flex items-center gap-2">
            {session?.user ? (
              <UserNav user={session.user} />
            ) : (
              <div className="hidden items-center gap-2 sm:flex">
                <Button variant="outline" asChild size="sm"><Link href="/login">Connexion</Link></Button>
                <Button asChild size="sm"><Link href="/register">S&apos;inscrire</Link></Button>
              </div>
            )}
          </div>
        </div>
      </header>
      <MobileNav navLinks={navLinks} isLoggedIn={!!session?.user} />
    </>
  )
}
