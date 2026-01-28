'use client'

import { useState } from 'react'
import Link from 'next/link'
import { Menu, X } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { cn } from '@/app/lib/utils'

interface MobileNavProps {
  navLinks: { href: string; label: string }[]
  isLoggedIn: boolean
}

export function MobileNav({ navLinks, isLoggedIn }: MobileNavProps) {
  const [isOpen, setIsOpen] = useState(false)

  return (
    <div className="md:hidden">
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setIsOpen(!isOpen)}
        className="relative z-50"
        aria-label="Toggle menu"
      >
        {isOpen ? (
          <X className="h-5 w-5" />
        ) : (
          <Menu className="h-5 w-5" />
        )}
      </Button>

      {/* Mobile Menu Overlay */}
      <div
        className={cn(
          'fixed inset-0 z-40 bg-background/80 backdrop-blur-sm transition-opacity duration-300',
          isOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'
        )}
        onClick={() => setIsOpen(false)}
      />

      {/* Mobile Menu Panel */}
      <div
        className={cn(
          'fixed top-16 right-0 z-40 h-[calc(100vh-4rem)] w-full sm:w-80 bg-background border-l shadow-2xl transition-transform duration-300 ease-out',
          isOpen ? 'translate-x-0' : 'translate-x-full'
        )}
      >
        <div className="flex flex-col h-full">
          {/* Navigation Links */}
          <nav className="flex-1 p-6 space-y-2">
            {navLinks.map((link, index) => (
              <Link
                key={link.href}
                href={link.href}
                onClick={() => setIsOpen(false)}
                className={cn(
                  'flex items-center px-4 py-3 text-lg font-medium rounded-xl transition-all duration-200',
                  'hover:bg-gradient-to-r hover:from-blue-50 hover:to-violet-50 hover:text-blue-600',
                  'dark:hover:from-blue-950/50 dark:hover:to-violet-950/50',
                  'animate-slide-in-right'
                )}
                style={{ animationDelay: `${index * 50}ms` }}
              >
                {link.label}
              </Link>
            ))}
          </nav>

          {/* Auth Buttons */}
          {!isLoggedIn && (
            <div className="p-6 border-t space-y-3 animate-fade-in" style={{ animationDelay: '200ms' }}>
              <Button variant="outline" className="w-full" asChild onClick={() => setIsOpen(false)}>
                <Link href="/login">Connexion</Link>
              </Button>
              <Button className="w-full" asChild onClick={() => setIsOpen(false)}>
                <Link href="/register">S&apos;inscrire</Link>
              </Button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
