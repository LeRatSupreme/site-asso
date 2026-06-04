import { ReactNode } from 'react'
import { AdminSidebar, AdminSidebarMobileTrigger } from './AdminSidebar'

interface DashboardLayoutProps {
  children: ReactNode
}

export function DashboardLayout({ children }: DashboardLayoutProps) {
  return (
    <div className="flex min-h-[calc(100vh-4.5rem)] bg-background">
      {/* Desktop sidebar */}
      <AdminSidebar />

      {/* Main area */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Mobile header bar (visible only on small screens) */}
        <div className="md:hidden flex items-center gap-3 px-4 py-3 border-b border-white/[0.07] bg-background/90 backdrop-blur-sm sticky top-[68px] z-30">
          <AdminSidebarMobileTrigger />
          <div className="flex items-center gap-2">
            <div className="h-6 w-6 rounded-full border border-primary/40 bg-primary/10 flex items-center justify-center">
              <span className="text-white font-black text-[9px]">AE</span>
            </div>
            <span className="text-sm font-semibold text-muted-foreground">Administration</span>
          </div>
        </div>

        {/* Page content */}
        <main className="flex-1 p-4 md:p-6 lg:p-8 overflow-auto">
          {children}
        </main>
      </div>
    </div>
  )
}
