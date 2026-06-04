import { Metadata } from 'next'
import { BookOpen } from 'lucide-react'
import { prisma } from '@/app/lib/prisma'

export const dynamic = 'force-dynamic'

export const metadata: Metadata = {
  title: 'Présentation',
  description: 'Découvrez notre association étudiante',
}

export default async function PresentationPage() {
  let page: Awaited<ReturnType<typeof prisma.page.findUnique>> = null

  try {
    page = await prisma.page.findUnique({
      where: { slug: 'presentation', isPublished: true },
    })
  } catch (error) {
    console.error('Public presentation page query failed:', error)
  }

  return (
    <div>
      {/* ─── Page Hero ────────────────────────────────────────────── */}
      <section className="page-hero">
        <div className="absolute -right-32 -top-52 size-[38rem] rounded-full bg-[radial-gradient(circle,rgba(72,189,211,.15),transparent_65%)]" />

        <div className="container relative">
          <div className="max-w-2xl">
            <div className="eyebrow mb-5">
              <BookOpen className="h-3.5 w-3.5" />
              <span>
                Qui sommes-nous ?
              </span>
            </div>

            <h1 className="text-5xl md:text-6xl lg:text-7xl font-black uppercase leading-[.93] tracking-[-.06em] mb-4 text-white">
              <span>
                {page?.title || 'Présentation'}
              </span>
            </h1>
            <p className="text-sm leading-7 text-muted-foreground max-w-lg">
              Notre histoire, notre mission et nos valeurs en tant qu&apos;association étudiante informatique.
            </p>
          </div>
        </div>
      </section>

      {/* ─── Content ──────────────────────────────────────────────── */}
      <div className="container py-12 md:py-16">
        <div className="max-w-3xl">
          {page?.content ? (
            <div
              className="prose prose-lg max-w-none prose-invert prose-headings:font-black prose-headings:tracking-tight prose-a:text-primary prose-strong:text-foreground"
              dangerouslySetInnerHTML={{ __html: page.content }}
            />
          ) : (
            <div className="relative overflow-hidden rounded-2xl border-2 border-dashed border-border/60 bg-muted/20 py-16 px-8 text-center">
              <div className="absolute inset-0 dot-grid opacity-30" />
              <div className="relative">
                <div className="mx-auto w-14 h-14 rounded-2xl bg-primary/10 flex items-center justify-center mb-4">
                  <BookOpen className="h-7 w-7 text-primary" />
                </div>
                <p className="text-base font-semibold text-foreground/70 mb-1">
                  Contenu à venir
                </p>
                <p className="text-sm text-muted-foreground">
                  Cette page est en cours de rédaction par l&apos;équipe.
                </p>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
