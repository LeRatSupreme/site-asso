import { Metadata } from 'next'
import { PageForm } from '../PageForm'

export const metadata: Metadata = {
  title: 'Nouvelle page | Administration',
  description: 'Créer une nouvelle page',
}

export default function NewPagePage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Nouvelle page</h1>
        <p className="text-muted-foreground">
          Créez une nouvelle page pour le site
        </p>
      </div>

      <PageForm />
    </div>
  )
}
