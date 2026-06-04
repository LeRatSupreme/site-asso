import Link from 'next/link'
import { ChevronLeft, Code2 } from 'lucide-react'
import { getSetting } from '@/app/lib/config'

export default async function AuthLayout({
  children,
}: {
  children: React.ReactNode
}) {
  let siteName = 'AEIC'

  try {
    siteName = await getSetting('site_name') || 'AEIC'
  } catch {
    // Fallback pendant le build statique
  }

  return (
    <div className="min-h-screen flex flex-col relative overflow-hidden">
      {/* Background */}
      <div className="absolute inset-0 bg-[#08172d]" />

      {/* Floating blobs */}
      <div className="absolute top-0 left-0 w-[500px] h-[500px] bg-primary/10 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2 pointer-events-none" />

      {/* Top accent line */}
      <div className="absolute top-0 left-0 right-0 h-px bg-primary/60 z-10" />

      {/* Back link */}
      <div className="relative z-10 p-4 md:p-6">
        <Link
          href="/"
          className="inline-flex items-center gap-1.5 text-sm text-blue-100/75 hover:text-white transition-colors group"
        >
          <ChevronLeft className="h-4 w-4 group-hover:-translate-x-0.5 transition-transform" />
          Retour à l&apos;accueil
        </Link>
      </div>

      {/* Main content */}
      <div className="relative z-10 flex-1 flex flex-col items-center justify-center px-4 py-8">
        {/* AEIC Brand */}
        <Link href="/" className="flex flex-col items-center mb-8 group animate-fade-in">
          <div className="relative mb-4">
            <div className="h-16 w-16 rounded-full border border-white/15 bg-gradient-to-br from-violet-500 to-teal-400 flex items-center justify-center transition-all duration-300">
              <span className="text-white font-black text-xl tracking-tight">
                {siteName.slice(0, 2).toUpperCase()}
              </span>
            </div>
            {/* Glow ring */}
            <div className="absolute inset-0 rounded-full bg-primary opacity-0 group-hover:opacity-20 blur-xl transition-opacity duration-300" />
            {/* Online dot */}
            <span className="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-primary border-2 border-background" />
          </div>

          <div className="flex items-center gap-2">
            <Code2 className="h-4 w-4 text-primary" />
            <span className="text-xl font-black text-white">
              {siteName}
            </span>
          </div>
          <p className="text-xs text-blue-100/70 mt-0.5 font-medium tracking-wide text-center">
            Association Étudiante Informatique de Calais
          </p>
        </Link>

        {/* Auth form */}
        <div className="w-full max-w-[420px] animate-fade-in-up animation-delay-100">
          {children}
        </div>
      </div>

      {/* Footer */}
      <div className="relative z-10 p-4 md:p-6 text-center">
        <p className="text-xs text-blue-100/60">
          © {new Date().getFullYear()} {siteName} — Association Étudiante Informatique de Calais
        </p>
      </div>
    </div>
  )
}
