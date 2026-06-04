import { Metadata } from 'next'
import { ShieldCheck } from 'lucide-react'
import { prisma } from '@/app/lib/prisma'

export const dynamic = 'force-dynamic'

export const metadata: Metadata = {
  title: 'Politique de confidentialité',
  description: 'Politique de confidentialité et RGPD',
}

export default async function PrivacyPage() {
  const page = await prisma.page.findUnique({
    where: { slug: 'privacy', isPublished: true },
  })

  return (
    <div>
      <section className="page-hero text-white">
        <div className="absolute inset-0 hero-grid opacity-20" />
        <div className="container relative">
          <div className="eyebrow text-teal-300 mb-4"><ShieldCheck className="h-4 w-4" /> Vos données</div>
          <h1 className="max-w-3xl text-4xl md:text-6xl font-black text-white">{page?.title || 'Politique de confidentialité'}</h1>
        </div>
      </section>
      <div className="container py-10 md:py-16">
        <div className="surface max-w-4xl mx-auto p-6 md:p-10">
        
        {page?.content ? (
          <div 
            className="prose prose-lg max-w-none dark:prose-invert"
            dangerouslySetInnerHTML={{ __html: page.content }}
          />
        ) : (
          <div className="text-center py-12 bg-muted/50 rounded-xl">
            <p className="text-muted-foreground">
              Le contenu de cette page n&apos;a pas encore été défini.
            </p>
          </div>
        )}
      </div>
    </div>
    </div>
  )
}
