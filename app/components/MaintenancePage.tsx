import { Wrench, Mail } from 'lucide-react'
import { getSetting } from '@/app/lib/config'

export default async function MaintenancePage() {
  const siteName = await getSetting('site_name')
  const contactEmail = await getSetting('contact_email')

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-background to-muted/30">
      <div className="text-center px-4 max-w-lg">
        <div className="mb-8 flex justify-center">
          <div className="relative">
            <div className="w-24 h-24 rounded-full bg-primary/10 flex items-center justify-center">
              <Wrench className="h-12 w-12 text-primary animate-pulse" />
            </div>
          </div>
        </div>
        
        <h1 className="text-4xl font-bold mb-4">
          Site en maintenance
        </h1>
        
        <p className="text-xl text-muted-foreground mb-8">
          {siteName || 'Notre site'} est actuellement en maintenance pour améliorer votre expérience.
          Nous serons de retour très bientôt !
        </p>
        
        <div className="bg-card border rounded-lg p-6 mb-8">
          <p className="text-sm text-muted-foreground">
            Nous travaillons dur pour revenir en ligne le plus rapidement possible.
            Merci de votre patience et de votre compréhension.
          </p>
        </div>

        {contactEmail && (
          <div className="flex items-center justify-center gap-2 text-muted-foreground">
            <Mail className="h-4 w-4" />
            <span>Contact : </span>
            <a 
              href={`mailto:${contactEmail}`}
              className="text-primary hover:underline"
            >
              {contactEmail}
            </a>
          </div>
        )}
      </div>
    </div>
  )
}
