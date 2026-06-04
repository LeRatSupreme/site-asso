'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { Calendar, Home, Info, LogIn, UserRound } from 'lucide-react'
import { cn } from '@/app/lib/utils'

interface MobileNavProps {
  navLinks: { href: string; label: string }[]
  isLoggedIn: boolean
}

const icons: Record<string, React.ElementType> = {
  '/': Home,
  '/events': Calendar,
  '/presentation': Info,
  '/team': UserRound,
}

export function MobileNav({ navLinks, isLoggedIn }: MobileNavProps) {
  const pathname = usePathname()
  const links = isLoggedIn
    ? navLinks
    : [...navLinks.slice(0, 3), { href: '/login', label: 'Connexion' }]

  return (
    <nav className="fixed inset-x-0 bottom-0 z-50 border-t border-white/[0.08] bg-[#08172d]/95 backdrop-blur-xl md:hidden">
      <div className="mx-auto grid max-w-md grid-cols-4">
        {links.map((link) => {
          const active = link.href === '/' ? pathname === '/' : pathname.startsWith(link.href)
          const Icon = link.href === '/login' ? LogIn : icons[link.href] || Home
          return (
            <Link
              key={link.href}
              href={link.href}
              className={cn(
                'relative flex min-w-0 flex-col items-center justify-center gap-1 px-1 py-2.5 transition-colors',
                active ? 'text-primary' : 'text-white/40'
              )}
            >
              {active && <span className="absolute inset-x-3 top-0 h-0.5 rounded-b-full bg-primary" />}
              <Icon className={cn('size-5 transition-transform', active && 'scale-110')} />
              <span className="max-w-full truncate text-[8px] font-bold uppercase tracking-[0.06em]">{link.label}</span>
            </Link>
          )
        })}
      </div>
    </nav>
  )
}
