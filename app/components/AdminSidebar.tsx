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
} from 'lucide-react'
import { cn } from '@/app/lib/utils'
import { Button } from '@/app/components/ui/button'
import { useState } from 'react'

const sidebarLinks = [
  {
    title: 'Dashboard',
    href: '/admin',
    icon: LayoutDashboard,
    color: 'text-blue-500',
  },
  {
    title: 'Événements',
    href: '/admin/events',
    icon: Calendar,
    color: 'text-violet-500',
  },
  {
    title: 'Cafétéria',
    href: '/admin/cafeteria',
    icon: Coffee,
    color: 'text-amber-500',
  },
  {
    title: 'Commandes',
    href: '/admin/cafeteria/commandes',
    icon: ShoppingBag,
    color: 'text-emerald-500',
  },
  {
    title: 'SumUp',
    href: '/admin/sumup',
    icon: CreditCard,
    color: 'text-indigo-500',
  },
  {
    title: 'Utilisateurs',
    href: '/admin/users',
    icon: Users,
    color: 'text-orange-500',
  },
  {
    title: 'Pages',
    href: '/admin/pages',
    icon: FileText,
    color: 'text-pink-500',
  },
  {
    title: 'Médias',
    href: '/admin/media',
    icon: ImageIcon,
    color: 'text-cyan-500',
  },
  {
    title: 'Paramètres',
    href: '/admin/settings',
    icon: Settings,
    color: 'text-gray-500',
  },
]

export function AdminSidebar() {
  const pathname = usePathname()
  const [collapsed, setCollapsed] = useState(false)

  return (
    <aside 
      className={cn(
        'sticky top-16 h-[calc(100vh-4rem)] border-r border-border/50 bg-card/50 backdrop-blur-sm transition-all duration-300',
        collapsed ? 'w-[72px]' : 'w-64'
      )}
    >
      <div className="flex flex-col h-full">
        {/* Header */}
        {!collapsed && (
          <div className="p-4 border-b border-border/50">
            <div className="flex items-center gap-2 text-sm font-semibold">
              <div className="p-1.5 rounded-lg bg-gradient-to-br from-blue-500 to-violet-500">
                <Sparkles className="h-4 w-4 text-white" />
              </div>
              <span className="bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
                Administration
              </span>
            </div>
          </div>
        )}
        
        {/* Navigation */}
        <div className="flex-1 py-4 overflow-y-auto">
          <nav className="space-y-1 px-3">
            {sidebarLinks.map((link) => {
              // Logique améliorée pour éviter les conflits entre /admin/cafeteria et /admin/cafeteria/commandes
              const isExactMatch = pathname === link.href
              const isNestedMatch = link.href !== '/admin' && 
                pathname.startsWith(link.href + '/') &&
                // Ne pas matcher /admin/cafeteria si on est sur /admin/cafeteria/commandes
                !sidebarLinks.some(other => other.href !== link.href && pathname.startsWith(other.href) && other.href.startsWith(link.href))
              const isActive = isExactMatch || isNestedMatch
              
              return (
                <Link
                  key={link.href}
                  href={link.href}
                  className={cn(
                    'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
                    isActive
                      ? 'bg-gradient-to-r from-blue-500 to-violet-500 text-white shadow-lg shadow-blue-500/25'
                      : 'text-muted-foreground hover:bg-blue-50 hover:text-blue-600 dark:hover:bg-blue-950/50 dark:hover:text-blue-400'
                  )}
                >
                  <link.icon className={cn(
                    'h-5 w-5 flex-shrink-0 transition-colors',
                    isActive ? 'text-white' : link.color
                  )} />
                  {!collapsed && <span>{link.title}</span>}
                </Link>
              )
            })}
          </nav>
        </div>
        
        {/* Collapse Button */}
        <div className="border-t border-border/50 p-3">
          <Button
            variant="ghost"
            size="sm"
            className={cn(
              'w-full justify-center hover:bg-blue-50 dark:hover:bg-blue-950/50',
              collapsed && 'px-0'
            )}
            onClick={() => setCollapsed(!collapsed)}
          >
            {collapsed ? (
              <ChevronRight className="h-4 w-4 text-blue-500" />
            ) : (
              <>
                <ChevronLeft className="h-4 w-4 mr-2 text-blue-500" />
                <span className="text-muted-foreground">Réduire</span>
              </>
            )}
          </Button>
        </div>
      </div>
    </aside>
  )
}
