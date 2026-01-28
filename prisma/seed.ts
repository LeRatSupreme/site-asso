import { PrismaClient } from '@prisma/client'
import { hash } from 'bcryptjs'

const prisma = new PrismaClient()

async function main() {
  console.log('🌱 Seeding database...')

  // Créer l'admin par défaut
  const adminPassword = await hash('admin123', 12)
  const admin = await prisma.user.upsert({
    where: { email: 'admin@asso.fr' },
    update: {},
    create: {
      email: 'admin@asso.fr',
      name: 'Administrateur',
      password: adminPassword,
      role: 'ADMIN',
      isActive: true,
    },
  })
  console.log('✅ Admin créé:', admin.email)

  // Créer un élève de test
  const elevePassword = await hash('eleve123', 12)
  const eleve = await prisma.user.upsert({
    where: { email: 'eleve@asso.fr' },
    update: {},
    create: {
      email: 'eleve@asso.fr',
      name: 'Élève Test',
      password: elevePassword,
      role: 'ELEVE',
      isActive: true,
    },
  })
  console.log('✅ Élève créé:', eleve.email)

  // Créer les paramètres par défaut
  const defaultSettings = [
    { key: 'site_name', value: 'Mon Association', label: 'Nom du site', group: 'general', type: 'text' },
    { key: 'site_description', value: 'Bienvenue sur le site de notre association', label: 'Description du site', group: 'general', type: 'textarea' },
    { key: 'contact_email', value: 'contact@asso.fr', label: 'Email de contact', group: 'general', type: 'email' },
    { key: 'contact_address', value: '', label: 'Adresse', group: 'general', type: 'textarea' },
    { key: 'logo_url', value: '', label: 'URL du logo', group: 'appearance', type: 'image' },
    { key: 'hero_image', value: '', label: 'Image de la page d\'accueil', group: 'appearance', type: 'image' },
    { key: 'social_facebook', value: '', label: 'Facebook', group: 'social', type: 'url' },
    { key: 'social_instagram', value: '', label: 'Instagram', group: 'social', type: 'url' },
    { key: 'social_twitter', value: '', label: 'Twitter', group: 'social', type: 'url' },
    { key: 'social_linkedin', value: '', label: 'LinkedIn', group: 'social', type: 'url' },
    { key: 'social_discord', value: '', label: 'Discord', group: 'social', type: 'url' },
    { key: 'registration_open', value: 'true', label: 'Inscriptions ouvertes', group: 'features', type: 'boolean' },
    { key: 'orders_enabled', value: 'true', label: 'Commandes activées', group: 'features', type: 'boolean' },
    { key: 'maintenance_mode', value: 'false', label: 'Mode maintenance', group: 'features', type: 'boolean' },
    { key: 'cafeteria_hours', value: '10h00 - 14h00', label: 'Horaires cafétéria', group: 'cafeteria', type: 'text' },
    { key: 'cafeteria_message', value: '', label: 'Message cafétéria', group: 'cafeteria', type: 'textarea' },
  ]

  for (const setting of defaultSettings) {
    await prisma.setting.upsert({
      where: { key: setting.key },
      update: {},
      create: setting,
    })
  }
  console.log('✅ Paramètres créés')

  // Créer les pages par défaut
  const defaultPages = [
    {
      slug: 'home',
      title: 'Accueil',
      content: '<h1>Bienvenue sur notre site</h1><p>Contenu de la page d\'accueil à personnaliser depuis l\'administration.</p>',
      isPublished: true,
    },
    {
      slug: 'presentation',
      title: 'Présentation',
      content: '<h1>Qui sommes-nous ?</h1><p>Présentation de l\'association à personnaliser depuis l\'administration.</p>',
      isPublished: true,
    },
    {
      slug: 'team',
      title: 'Notre équipe',
      content: '<h1>L\'équipe</h1><p>Présentez votre équipe ici.</p>',
      isPublished: true,
    },
    {
      slug: 'legal',
      title: 'Mentions légales',
      content: '<h1>Mentions légales</h1><p>Contenu des mentions légales à personnaliser.</p>',
      isPublished: true,
    },
    {
      slug: 'privacy',
      title: 'Politique de confidentialité',
      content: '<h1>Politique de confidentialité</h1><p>Contenu RGPD à personnaliser.</p>',
      isPublished: true,
    },
  ]

  for (const page of defaultPages) {
    await prisma.page.upsert({
      where: { slug: page.slug },
      update: {},
      create: page,
    })
  }
  console.log('✅ Pages créées')

  // Créer quelques événements de démonstration
  const events = [
    {
      title: 'Soirée de bienvenue',
      description: '<p>Rejoignez-nous pour notre soirée de bienvenue annuelle ! Au programme : rencontres, animations et buffet.</p>',
      date: new Date('2026-02-15T18:00:00'),
      location: 'Salle des fêtes',
      isPublished: true,
    },
    {
      title: 'Vente de pizzas',
      description: '<p>Grande vente de pizzas pour financer nos projets. Commandez à l\'avance !</p>',
      date: new Date('2026-03-01T12:00:00'),
      location: 'Hall principal',
      isPublished: true,
    },
    {
      title: 'Assemblée générale',
      description: '<p>Assemblée générale annuelle de l\'association. Tous les membres sont invités.</p>',
      date: new Date('2026-04-10T14:00:00'),
      location: 'Amphithéâtre A',
      isPublished: false,
    },
  ]

  for (const event of events) {
    const existingEvent = await prisma.event.findFirst({
      where: { title: event.title },
    })
    if (!existingEvent) {
      await prisma.event.create({ data: event })
    }
  }
  console.log('✅ Événements créés')

  console.log('✅ Seed terminé avec succès!')
  console.log('')
  console.log('📧 Comptes de test:')
  console.log('   Admin: admin@asso.fr / admin123')
  console.log('   Élève: eleve@asso.fr / eleve123')
}

main()
  .catch((e) => {
    console.error('❌ Erreur lors du seed:', e)
    process.exit(1)
  })
  .finally(async () => {
    await prisma.$disconnect()
  })
