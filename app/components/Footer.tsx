import Link from 'next/link'
import { getSettings } from '@/app/lib/config'

export async function Footer() {
  let settings: Record<string, string | null> = { site_name: 'AEIC', contact_email: null }
  try {
    settings = await getSettings(['site_name', 'contact_email'])
  } catch {}

  return (
    <footer className="border-t border-white/[0.07]">
      <div className="mx-auto flex max-w-7xl flex-col gap-5 px-4 py-8 text-[11px] text-white/40 sm:flex-row sm:items-center sm:justify-between lg:px-8">
        <div>
          <p className="font-black uppercase tracking-[0.14em] text-white">{settings.site_name || 'AEIC'}</p>
          <p className="mt-1">Association Étudiante Informatique de Calais</p>
        </div>
        <div className="flex flex-wrap gap-x-5 gap-y-2 font-bold uppercase tracking-[0.1em]">
          <Link href="/events" className="hover:text-primary">Événements</Link>
          <Link href="/presentation" className="hover:text-primary">Association</Link>
          <Link href="/legal" className="hover:text-primary">Mentions légales</Link>
          {settings.contact_email && <a href={`mailto:${settings.contact_email}`} className="hover:text-primary">Contact</a>}
        </div>
        <span>© {new Date().getFullYear()} · 100 % étudiant</span>
      </div>
    </footer>
  )
}
