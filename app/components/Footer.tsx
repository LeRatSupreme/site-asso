import Link from 'next/link'
import { Facebook, Instagram, Twitter, Linkedin, Mail, Heart } from 'lucide-react'
import { getSettings } from '@/app/lib/config'

export async function Footer() {
  const settings = await getSettings([
    'site_name',
    'facebook_url',
    'instagram_url',
    'twitter_url',
    'linkedin_url',
    'contact_email',
  ])

  const socialLinks = [
    { url: settings.facebook_url, icon: Facebook, label: 'Facebook', color: 'hover:text-blue-600' },
    { url: settings.instagram_url, icon: Instagram, label: 'Instagram', color: 'hover:text-pink-600' },
    { url: settings.twitter_url, icon: Twitter, label: 'Twitter', color: 'hover:text-blue-400' },
    { url: settings.linkedin_url, icon: Linkedin, label: 'LinkedIn', color: 'hover:text-blue-700' },
  ].filter((link) => link.url)

  const quickLinks = [
    { href: '/', label: 'Accueil' },
    { href: '/events', label: 'Événements' },
    { href: '/presentation', label: 'Présentation' },
    { href: '/team', label: 'Équipe' },
  ]

  const legalLinks = [
    { href: '/legal', label: 'Mentions légales' },
    { href: '/privacy', label: 'Confidentialité' },
  ]

  return (
    <footer className="relative border-t border-border/50 bg-gradient-to-b from-background to-muted/30">
      {/* Decorative gradient line */}
      <div className="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-blue-500/50 to-transparent" />
      
      <div className="container py-12 md:py-16">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 lg:gap-8">
          {/* Brand Section */}
          <div className="lg:col-span-1">
            <Link href="/" className="inline-flex items-center gap-2 group mb-4">
              <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-blue-500 to-violet-500 flex items-center justify-center shadow-lg shadow-blue-500/20 group-hover:shadow-violet-500/30 transition-all duration-300">
                <span className="text-white font-bold text-lg">
                  {(settings.site_name || 'A')[0].toUpperCase()}
                </span>
              </div>
              <span className="font-bold text-xl bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-transparent">
                {settings.site_name || 'Association'}
              </span>
            </Link>
            <p className="text-muted-foreground text-sm leading-relaxed mb-4">
              Rejoignez notre communauté étudiante dynamique et participez à nos événements !
            </p>
            {settings.contact_email && (
              <a 
                href={`mailto:${settings.contact_email}`}
                className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-blue-500 transition-colors group"
              >
                <Mail className="h-4 w-4 group-hover:scale-110 transition-transform" />
                {settings.contact_email}
              </a>
            )}
          </div>

          {/* Quick Links */}
          <div>
            <h4 className="font-semibold mb-4 text-sm uppercase tracking-wider text-foreground">
              Navigation
            </h4>
            <ul className="space-y-3">
              {quickLinks.map((link) => (
                <li key={link.href}>
                  <Link 
                    href={link.href} 
                    className="text-sm text-muted-foreground hover:text-blue-500 transition-colors inline-flex items-center gap-1 group"
                  >
                    <span className="w-0 h-px bg-blue-500 group-hover:w-3 transition-all duration-200" />
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Legal Links */}
          <div>
            <h4 className="font-semibold mb-4 text-sm uppercase tracking-wider text-foreground">
              Légal
            </h4>
            <ul className="space-y-3">
              {legalLinks.map((link) => (
                <li key={link.href}>
                  <Link 
                    href={link.href} 
                    className="text-sm text-muted-foreground hover:text-blue-500 transition-colors inline-flex items-center gap-1 group"
                  >
                    <span className="w-0 h-px bg-blue-500 group-hover:w-3 transition-all duration-200" />
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {/* Social Links */}
          <div>
            <h4 className="font-semibold mb-4 text-sm uppercase tracking-wider text-foreground">
              Réseaux sociaux
            </h4>
            {socialLinks.length > 0 ? (
              <div className="flex gap-3">
                {socialLinks.map((link) => (
                  <a
                    key={link.label}
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`p-2.5 rounded-xl bg-muted/50 text-muted-foreground transition-all duration-200 hover:scale-110 ${link.color}`}
                    aria-label={link.label}
                  >
                    <link.icon className="h-5 w-5" />
                  </a>
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                Bientôt disponible
              </p>
            )}
          </div>
        </div>

        {/* Bottom Bar */}
        <div className="border-t border-border/50 mt-10 pt-8 flex flex-col sm:flex-row items-center justify-between gap-4">
          <p className="text-sm text-muted-foreground">
            © {new Date().getFullYear()} {settings.site_name || 'Association'}. 
            Tous droits réservés.
          </p>
          <p className="text-sm text-muted-foreground flex items-center gap-1">
            Développé par<Link href="https://www.linkedin.com/in/sofiane-zemrani" target="_blank" rel="noopener noreferrer"> Sofiane Zemrani</Link>
          </p>
        </div>
      </div>
    </footer>
  )
}
