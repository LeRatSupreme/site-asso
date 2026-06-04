import Link from 'next/link'
import { ArrowRight, CalendarDays, Coffee, Users, Zap } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { EventCard } from '@/app/components/EventCard'
import { prisma } from '@/app/lib/prisma'
import { getSettings } from '@/app/lib/config'
import { getAuthSession } from '@/app/lib/permissions'

export const dynamic = 'force-dynamic'

export default async function HomePage() {
  const [settings, session, upcomingEvents, eventsCount, usersCount] = await Promise.all([
    getSettings(['site_name', 'site_description']),
    getAuthSession(),
    prisma.event.findMany({
      where: { isPublished: true, date: { gte: new Date() } },
      orderBy: { date: 'asc' },
      take: 3,
    }),
    prisma.event.count({ where: { isPublished: true } }),
    prisma.user.count({ where: { isActive: true } }),
  ])
  const dashboardUrl = session?.user?.role === 'ADMIN' ? '/admin' : '/eleve'

  return (
    <div className="overflow-hidden">
      <section className="relative border-b border-white/[0.07]">
        <div className="pointer-events-none absolute -right-40 -top-52 size-[48rem] rounded-full bg-[radial-gradient(circle,rgba(72,189,211,.16),transparent_65%)]" />
        <div className="pointer-events-none absolute -bottom-40 -left-36 size-[32rem] rounded-full bg-[radial-gradient(circle,rgba(97,80,170,.13),transparent_65%)]" />

        <div className="mx-auto grid max-w-7xl gap-12 px-4 py-16 lg:grid-cols-[1.1fr_.9fr] lg:px-8 lg:py-24">
          <div className="relative z-10">
            <span className="eyebrow">Association étudiante · Informatique · Calais</span>
            <h1 className="mt-5 max-w-3xl text-5xl font-black uppercase leading-[.93] tracking-[-.065em] text-white sm:text-6xl lg:text-7xl">
              Plus qu&apos;une asso.
              <span className="block text-primary">Ton campus, en mieux.</span>
            </h1>
            <p className="mt-5 max-w-lg text-base leading-7 text-muted-foreground">
              {settings.site_description || 'Événements, cafétéria et vie étudiante. L’AEIC rassemble les étudiants en informatique de Calais.'}
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Button asChild size="lg">
                <Link href={session?.user ? dashboardUrl : '/register'}>
                  {session?.user ? 'Accéder à mon espace' : "Rejoindre l'AEIC"}
                  <ArrowRight className="size-4" />
                </Link>
              </Button>
              <Button asChild variant="outline" size="lg"><Link href="/events">Voir les événements</Link></Button>
            </div>
          </div>

          <div className="relative hidden min-h-[360px] items-center justify-center lg:flex">
            <div className="absolute inset-0 rotate-[-3deg] rounded-[2rem] border border-primary/15 bg-gradient-to-br from-primary/[0.06] to-transparent" />
            <div className="surface relative w-full rotate-[1.5deg] p-7">
              <span className="eyebrow">L&apos;AEIC en quelques chiffres</span>
              <h2 className="mt-5 text-4xl font-black uppercase leading-[.95] tracking-[-.055em] text-white">
                Fait par les étudiants.
                <span className="block text-primary">Pour les étudiants.</span>
              </h2>
              <div className="mt-8 grid grid-cols-2 gap-2">
                {[
                  ['100 %', 'Étudiant'],
                  [usersCount, 'Membres actifs'],
                  [eventsCount, 'Événements'],
                  ['0 %', 'Prise de tête'],
                ].map(([value, label], index) => (
                  <div
                    key={label}
                    className={index === 0 ? 'rounded-xl border border-primary/35 bg-primary/[0.08] p-4' : 'rounded-xl border border-white/[0.08] bg-white/[0.03] p-4'}
                  >
                    <p className="text-2xl font-black text-white">{value}</p>
                    <p className="mt-2 text-[9px] font-bold uppercase tracking-[.16em] text-muted-foreground">{label}</p>
                  </div>
                ))}
              </div>
              <div className="mt-8 flex items-center gap-3 border-t border-white/[0.07] pt-5">
                <div className="grid size-9 place-items-center rounded-full bg-gradient-to-br from-violet-500 to-teal-400 text-xs font-black text-white">AE</div>
                <div>
                  <p className="text-xs font-black uppercase tracking-[.12em] text-white">AEIC Calais</p>
                  <p className="mt-1 text-[9px] font-bold uppercase tracking-[.14em] text-primary">Depuis le campus, pour le campus</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-7xl px-4 py-14 lg:px-8">
        <div className="flex items-end justify-between gap-4">
          <div><span className="eyebrow">À l&apos;agenda</span><h2 className="section-title mt-2">Prochains événements</h2></div>
          <Link href="/events" className="text-[11px] font-black uppercase tracking-[.18em] text-primary">Tout voir →</Link>
        </div>
        {upcomingEvents.length > 0 ? (
          <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {upcomingEvents.map((event) => <EventCard key={event.id} event={event} />)}
          </div>
        ) : (
          <div className="surface mt-6 p-10 text-center text-muted-foreground">Aucun événement annoncé pour le moment.</div>
        )}
      </section>

      <section className="border-y border-white/[0.07] bg-white/[0.02]">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 lg:grid-cols-[.8fr_1.2fr] lg:px-8">
          <div>
            <span className="eyebrow">Tout au même endroit</span>
            <h2 className="section-title mt-2">La vie étudiante, sans friction.</h2>
            <p className="mt-5 max-w-sm text-sm leading-7 text-muted-foreground">
              Participe aux événements, commande à la cafétéria et retrouve toutes les informations de l&apos;association.
            </p>
            <Button asChild className="mt-8">
              <Link href={session?.user ? dashboardUrl : '/register'}>
                {session?.user ? 'Accéder à mon espace' : 'Créer mon compte'}
              </Link>
            </Button>
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            {[
              [CalendarDays, 'Événements', 'Découvre et rejoins les prochains rendez-vous.'],
              [Coffee, 'Cafétéria', 'Commande rapidement depuis ton espace membre.'],
              [Users, 'Communauté', 'Retrouve les étudiants qui font vivre le campus.'],
            ].map(([Icon, title, text]) => {
              const FeatureIcon = Icon as typeof Zap
              return (
                <div key={title as string} className="surface p-5">
                  <FeatureIcon className="size-5 text-primary" />
                  <h3 className="mt-8 text-sm font-black uppercase tracking-[-.02em] text-white">{title as string}</h3>
                  <p className="mt-3 text-xs leading-6 text-muted-foreground">{text as string}</p>
                </div>
              )
            })}
          </div>
        </div>
      </section>
    </div>
  )
}
