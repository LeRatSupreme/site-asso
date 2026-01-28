import type { Metadata } from 'next'
import { Inter } from 'next/font/google'
import './globals.css'
import { Toaster } from '@/app/components/ui/toaster'
import { SessionProvider } from '@/app/components/SessionProvider'
import { ThemeProvider } from '@/app/components/ThemeProvider'
import { getSetting } from '@/app/lib/config'

const inter = Inter({ subsets: ['latin'] })

export async function generateMetadata(): Promise<Metadata> {
  try {
    const siteName = await getSetting('site_name')
    const siteDescription = await getSetting('site_description')
    
    return {
      title: {
        default: siteName || 'Association',
        template: `%s | ${siteName || 'Association'}`,
      },
      description: siteDescription || 'Site web associatif',
    }
  } catch {
    // Fallback pendant le build statique (DB non accessible)
    return {
      title: {
        default: 'Association',
        template: '%s | Association',
      },
      description: 'Site web associatif',
    }
  }
}

export default function RootLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <html lang="fr" suppressHydrationWarning>
      <body className={inter.className}>
        <ThemeProvider
          attribute="class"
          defaultTheme="system"
          enableSystem
          disableTransitionOnChange
        >
          <SessionProvider>
            {children}
          </SessionProvider>
          <Toaster />
        </ThemeProvider>
      </body>
    </html>
  )
}
