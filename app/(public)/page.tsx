import Link from 'next/link'
import Image from 'next/image'
import { ArrowRight, Calendar, Users, Heart, Sparkles, Zap } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/app/components/ui/card'
import { EventCard } from '@/app/components/EventCard'
import { prisma } from '@/app/lib/prisma'
import { getSettings } from '@/app/lib/config'

// Force le rendu dynamique (pas de pré-rendu statique)
export const dynamic = 'force-dynamic'

export default async function HomePage() {
  const settings = await getSettings([
    'site_name',
    'site_description',
    'hero_image',
  ])

  // Récupérer le contenu de la page d'accueil
  const homePage = await prisma.page.findUnique({
    where: { slug: 'home' },
  })

  // Récupérer les prochains événements
  const upcomingEvents = await prisma.event.findMany({
    where: {
      isPublished: true,
      date: { gte: new Date() },
    },
    orderBy: { date: 'asc' },
    take: 3,
  })

  // Statistiques
  const [eventsCount, usersCount] = await Promise.all([
    prisma.event.count({ where: { isPublished: true } }),
    prisma.user.count({ where: { isActive: true } }),
  ])

  return (
    <div className="overflow-hidden">
      {/* Hero Section */}
      <section className="relative min-h-[85vh] flex items-center justify-center overflow-hidden">
        {/* Background Gradient */}
        <div className="absolute inset-0 bg-gradient-to-br from-blue-50 via-violet-50/50 to-background dark:from-blue-950/30 dark:via-violet-950/20 dark:to-background" />
        
        {/* Animated Blobs */}
        <div className="absolute top-20 -left-20 w-72 h-72 bg-blue-500/20 rounded-full blur-3xl animate-float" />
        <div className="absolute bottom-20 -right-20 w-96 h-96 bg-violet-500/20 rounded-full blur-3xl animate-float animation-delay-400" />
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-gradient-radial from-blue-500/10 via-violet-500/5 to-transparent rounded-full blur-2xl" />
        
        {settings.hero_image && (
          <div className="absolute inset-0 z-0">
            <Image
              src={settings.hero_image}
              alt="Hero"
              fill
              className="object-cover opacity-10"
            />
          </div>
        )}
        
        <div className="container relative z-10">
          <div className="max-w-4xl mx-auto text-center">
            {/* Badge */}
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gradient-to-r from-blue-500/10 to-violet-500/10 border border-blue-500/20 mb-8 animate-fade-in">
              <Sparkles className="h-4 w-4 text-violet-500" />
              <span className="text-sm font-medium text-foreground">Association étudiante</span>
            </div>
            
            <h1 className="text-5xl md:text-7xl lg:text-8xl font-bold tracking-tight mb-6 animate-fade-in-up">
              <span className="bg-gradient-to-r from-blue-600 via-violet-600 to-blue-600 bg-clip-text text-transparent bg-[length:200%_auto] animate-shimmer">
                {settings.site_name || 'Bienvenue'}
              </span>
            </h1>
            
            <p className="text-xl md:text-2xl text-muted-foreground mb-10 max-w-2xl mx-auto leading-relaxed animate-fade-in-up animation-delay-200">
              {settings.site_description || 'Découvrez notre association et rejoignez-nous !'}
            </p>
            
            <div className="flex flex-col sm:flex-row gap-4 justify-center animate-fade-in-up animation-delay-400">
              <Button asChild size="xl" variant="gradient">
                <Link href="/events">
                  Voir les événements
                  <ArrowRight className="ml-2 h-5 w-5" />
                </Link>
              </Button>
              <Button asChild variant="outline" size="xl">
                <Link href="/presentation">En savoir plus</Link>
              </Button>
            </div>
          </div>
        </div>
        
        {/* Scroll Indicator */}
        <div className="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
          <div className="w-6 h-10 rounded-full border-2 border-muted-foreground/30 flex items-start justify-center p-2">
            <div className="w-1 h-2 bg-muted-foreground/50 rounded-full" />
          </div>
        </div>
      </section>

      {/* Stats Section */}
      <section className="py-20 relative">
        <div className="absolute inset-0 bg-gradient-to-b from-muted/50 to-background" />
        <div className="container relative">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {[
              { icon: Calendar, value: eventsCount, label: 'événements organisés', color: 'from-blue-500 to-cyan-500' },
              { icon: Users, value: usersCount, label: 'membres inscrits', color: 'from-violet-500 to-purple-500' },
              { icon: Heart, value: '100%', label: 'bénévole et passionné', color: 'from-pink-500 to-rose-500' },
            ].map((stat, index) => (
              <Card key={index} className="group overflow-hidden">
                <CardHeader className="flex flex-row items-center gap-4 pb-2">
                  <div className={`p-3 rounded-xl bg-gradient-to-br ${stat.color} shadow-lg`}>
                    <stat.icon className="h-6 w-6 text-white" />
                  </div>
                  <div>
                    <CardTitle className="text-4xl font-bold bg-gradient-to-r from-foreground to-foreground/70 bg-clip-text">
                      {stat.value}
                    </CardTitle>
                    <p className="text-sm text-muted-foreground">{stat.label}</p>
                  </div>
                </CardHeader>
              </Card>
            ))}
          </div>
        </div>
      </section>

      {/* Contenu de la page */}
      {homePage?.content && (
        <section className="py-20">
          <div className="container">
            <div 
              className="prose prose-lg max-w-none dark:prose-invert prose-headings:font-bold prose-a:text-blue-500 hover:prose-a:text-violet-500"
              dangerouslySetInnerHTML={{ __html: homePage.content }}
            />
          </div>
        </section>
      )}

      {/* Prochains événements */}
      {upcomingEvents.length > 0 && (
        <section className="py-20 relative">
          <div className="absolute inset-0 bg-gradient-to-br from-blue-50/50 via-transparent to-violet-50/50 dark:from-blue-950/20 dark:to-violet-950/20" />
          <div className="container relative">
            <div className="flex flex-col md:flex-row items-start md:items-center justify-between mb-12 gap-4">
              <div>
                <div className="flex items-center gap-2 mb-2">
                  <Zap className="h-5 w-5 text-violet-500" />
                  <span className="text-sm font-semibold text-violet-500 uppercase tracking-wider">À venir</span>
                </div>
                <h2 className="text-4xl font-bold">Prochains événements</h2>
                <p className="text-muted-foreground mt-2 text-lg">
                  Ne manquez pas nos prochaines activités
                </p>
              </div>
              <Button asChild variant="outline" size="lg">
                <Link href="/events">
                  Voir tous les événements
                  <ArrowRight className="ml-2 h-4 w-4" />
                </Link>
              </Button>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
              {upcomingEvents.map((event, index) => (
                <div 
                  key={event.id}
                  className="animate-fade-in-up"
                  style={{ animationDelay: `${index * 100}ms` }}
                >
                  <EventCard event={event} />
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* CTA Section */}
      <section className="py-20">
        <div className="container">
          <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-600 via-violet-600 to-violet-700 p-10 md:p-16 text-center text-white shadow-2xl shadow-violet-500/25">
            {/* Decorative elements */}
            <div className="absolute top-0 left-0 w-64 h-64 bg-white/10 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2" />
            <div className="absolute bottom-0 right-0 w-96 h-96 bg-blue-500/20 rounded-full blur-3xl translate-x-1/2 translate-y-1/2" />
            
            <div className="relative z-10">
              <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm mb-6">
                <Sparkles className="h-4 w-4" />
                <span className="text-sm font-medium">Rejoignez l&apos;aventure</span>
              </div>
              
              <h2 className="text-4xl md:text-5xl font-bold mb-6">Rejoignez-nous !</h2>
              <p className="text-lg md:text-xl opacity-90 mb-8 max-w-2xl mx-auto leading-relaxed">
                Inscrivez-vous pour participer à nos événements, passer des commandes 
                et rester informé de nos activités.
              </p>
              <Button asChild size="xl" className="bg-white text-violet-600 hover:bg-white/90 shadow-xl">
                <Link href="/register">
                  Créer un compte gratuitement
                  <ArrowRight className="ml-2 h-5 w-5" />
                </Link>
              </Button>
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}
