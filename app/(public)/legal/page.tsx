import { Metadata } from 'next'
import { prisma } from '@/app/lib/prisma'

export const dynamic = 'force-dynamic'

export const metadata: Metadata = {
  title: 'Mentions légales',
  description: 'Mentions légales du site',
}

export default async function LegalPage() {
  const page = await prisma.page.findUnique({
    where: { slug: 'legal', isPublished: true },
  })

  return (
    <div className="container py-8">
      <div className="max-w-4xl mx-auto">
        <h1 className="text-4xl font-bold mb-8">{page?.title || 'Mentions légales'}</h1>
        
        {page?.content ? (
          <div 
            className="prose prose-lg max-w-none"
            dangerouslySetInnerHTML={{ __html: page.content }}
          />
        ) : (
          <div className="text-center py-12 bg-muted/50 rounded-lg">
            <p className="text-muted-foreground">
              Le contenu de cette page n&apos;a pas encore été défini.
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
