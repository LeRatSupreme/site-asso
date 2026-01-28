'use client'

import * as React from 'react'
import { Moon, Sun } from 'lucide-react'
import { useTheme } from 'next-themes'
import { cn } from '@/app/lib/utils'

export function ThemeToggle() {
  const { theme, setTheme } = useTheme()
  const [mounted, setMounted] = React.useState(false)

  React.useEffect(() => {
    setMounted(true)
  }, [])

  if (!mounted) {
    return (
      <button 
        className="relative h-10 w-10 rounded-xl bg-muted flex items-center justify-center"
        disabled
      >
        <Sun className="h-5 w-5 text-muted-foreground" />
      </button>
    )
  }

  const isDark = theme === 'dark'

  return (
    <button
      onClick={() => setTheme(isDark ? 'light' : 'dark')}
      className={cn(
        'relative h-10 w-10 rounded-xl transition-all duration-300 flex items-center justify-center',
        'hover:scale-110 active:scale-95',
        isDark 
          ? 'bg-violet-500/10 text-violet-400 hover:bg-violet-500/20' 
          : 'bg-blue-500/10 text-blue-500 hover:bg-blue-500/20'
      )}
      title={isDark ? 'Mode clair' : 'Mode sombre'}
    >
      <Sun className={cn(
        'h-5 w-5 absolute transition-all duration-500',
        isDark ? 'rotate-0 scale-100' : 'rotate-90 scale-0'
      )} />
      <Moon className={cn(
        'h-5 w-5 absolute transition-all duration-500',
        isDark ? '-rotate-90 scale-0' : 'rotate-0 scale-100'
      )} />
      <span className="sr-only">Basculer le thème</span>
    </button>
  )
}
