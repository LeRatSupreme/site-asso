'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { cn } from '@/app/lib/utils'

interface NavLinksProps {
  links: { href: string; label: string }[]
}

export function NavLinks({ links }: NavLinksProps) {
  const pathname = usePathname()

  return (
    <div className="hidden md:flex items-center gap-1">
      {links.map((link) => {
        const active = link.href === '/' ? pathname === '/' : pathname.startsWith(link.href)
        return (
          <Link
            key={link.href}
            href={link.href}
            className={cn(
              'rounded-lg px-3 py-2 text-[11px] font-bold uppercase tracking-[0.12em] transition-colors',
              active ? 'bg-primary/[0.08] text-primary' : 'text-white/45 hover:bg-white/[0.04] hover:text-primary'
            )}
          >
            {link.label}
          </Link>
        )
      })}
    </div>
  )
}
