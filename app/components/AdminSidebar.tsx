'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import {
  LayoutDashboard,
  Calendar,
  ShoppingBag,
  Users,
  FileText,
  Settings,
  ImageIcon,
  ChevronLeft,
  ChevronRight,
  Sparkles,
  Coffee,
  CreditCard,
  Menu,
} from 'lucide-react'
import { cn } from '@/app/lib/utils'
import { Button } from '@/app/components/ui/button'
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/app/components/ui/sheet'
import { useState } from 'react'

const sidebarLinks = [
  { title: 'Dashboard', href: '/admin', icon: LayoutDashboard },
  { title: 'Événements', href: '/admin/events', icon: Calendar },
  { title: 'Cafétéria', href: '/admin/cafeteria', icon: Coffee },
  { title: 'Commandes', href: '/admin/cafeteria/commandes', icon: ShoppingBag },
  { title: 'SumUp', href: '/admin/sumup', icon: CreditCard },
  { title: 'Utilisateurs', href: '/admin/users', icon: Users },
  { title: 'Pages', href: '/admin/pages', icon: FileText },
  { title: 'Médias', href: '/admin/media', icon: ImageIcon },
  { title: 'Paramètres', href: '/admin/settings', icon: Settings },
]

function isLinkActive(pathname: string, href: string) {
  if (href === '/admin') return pathname === '/admin'
  return (
    pathname.startsWith(href + '/') &&
    !sidebarLinks.some(
      (other) => other.href !== href && pathname.startsWith(other.href) && other.href.startsWith(href)
    )
  ) || pathname === href
}

function SidebarContent({ collapsed = false, onLinkClick }: { collapsed?: boolean; onLinkClick?: () => void }) {
  const pathname = usePathname()

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      {!collapsed && (
        <div className="px-4 py-5 border-b border-border/70">
          <div className="flex items-center gap-2.5">
            <div className="p-1.5 rounded-lg bg-primary/10">
              <Sparkles className="h-4 w-4 text-primary" />
            </div>
            <span className="font-black text-xs uppercase tracking-[.12em] text-white">
              Administration
            </span>
          </div>
        </div>
      )}

      {/* Navigation */}
      <nav className="flex-1 py-3 px-2.5 overflow-y-auto space-y-0.5">
        {sidebarLinks.map((link) => {
          const isActive = isLinkActive(pathname, link.href)
          return (
            <Link
              key={link.href}
              href={link.href}
              onClick={onLinkClick}
              title={collapsed ? link.title : undefined}
              className={cn(
                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
                isActive
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground'
              )}
            >
              {/* Icon */}
              <div className={cn(
                'flex-shrink-0 p-1.5 rounded-lg transition-colors',
                isActive
                  ? 'bg-white/20'
                  : `bg-transparent`
              )}>
                <link.icon className={cn(
                  'h-4 w-4',
                  isActive ? 'text-primary-foreground' : 'text-white/35'
                )} />
              </div>
              {!collapsed && <span className="truncate">{link.title}</span>}
            </Link>
          )
        })}
      </nav>
    </div>
  )
}

/* ─── Mobile Trigger Button ─────────────────────────────────────── */
export function AdminSidebarMobileTrigger() {
  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="md:hidden h-9 w-9 rounded-xl"
          aria-label="Ouvrir le menu"
        >
          <Menu className="h-5 w-5" />
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="w-[280px] p-0 border-r border-border/50">
        <SheetTitle className="sr-only">Navigation administration</SheetTitle>
        <div className="h-full bg-card/50">
          <SidebarContent onLinkClick={() => {}} />
        </div>
      </SheetContent>
    </Sheet>
  )
}

/* ─── Desktop Sidebar ───────────────────────────────────────────── */
export function AdminSidebar() {
  const [collapsed, setCollapsed] = useState(false)

  return (
    <aside
      className={cn(
        'hidden md:flex flex-col sticky top-[68px] h-[calc(100vh-4.25rem)] border-r border-white/[0.07] bg-white/[0.02] transition-all duration-300 ease-in-out',
        collapsed ? 'w-[68px]' : 'w-60'
      )}
    >
      <SidebarContent collapsed={collapsed} />

      {/* Collapse toggle */}
      <div className="border-t border-border/50 p-2.5">
        <Button
          variant="ghost"
          size="sm"
          className={cn(
            'w-full rounded-xl h-9 transition-all',
            collapsed ? 'justify-center px-0' : 'justify-start gap-2'
          )}
          onClick={() => setCollapsed(!collapsed)}
        >
          {collapsed ? (
            <ChevronRight className="h-4 w-4 text-primary" />
          ) : (
            <>
              <ChevronLeft className="h-4 w-4 text-primary" />
              <span className="text-xs text-muted-foreground font-medium">Réduire</span>
            </>
          )}
        </Button>
      </div>
    </aside>
  )
}
