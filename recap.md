# Récapitulatif du Projet - Site Association

## 🏗️ Architecture Technique

### Stack Technologique
- **Framework**: Next.js 15.5.9 (App Router)
- **Base de données**: MySQL avec Prisma ORM
- **Authentification**: NextAuth.js (credentials provider)
- **UI**: Tailwind CSS + shadcn/ui
- **Paiements**: Intégration SumUp API
- **Hébergement fichiers**: Upload local dans `/public/uploads`

### Structure des Dossiers
```
/app
├── (public)/              # Pages publiques (accueil, événements, etc.)
│   └── layout.tsx         # Layout avec vérification maintenance
├── (dashboard)/           # Espace connecté
│   ├── admin/             # Dashboard administrateur
│   │   ├── cafeteria/     # Gestion produits & commandes
│   │   ├── events/        # Gestion événements
│   │   ├── media/         # Gestion médias
│   │   ├── pages/         # Éditeur de pages
│   │   ├── settings/      # Paramètres du site
│   │   ├── sumup/         # Dashboard paiements SumUp
│   │   └── users/         # Gestion utilisateurs
│   ├── eleve/             # Dashboard étudiant
│   │   ├── cafeteria/     # Commander à la cafétéria
│   │   ├── commandes/     # Historique commandes
│   │   ├── inscriptions/  # Mes inscriptions événements
│   │   └── profile/       # Mon profil
│   └── layout.tsx         # Layout avec vérification rôle + maintenance
├── actions/               # Server Actions
├── api/                   # Routes API
├── components/            # Composants réutilisables
└── lib/                   # Utilitaires (auth, prisma, config, utils)
```

### Fichiers Clés
- `/app/lib/auth.ts` - Configuration NextAuth (⚠️ import: `@/app/lib/auth`)
- `/app/lib/prisma.ts` - Client Prisma singleton
- `/app/lib/config.ts` - Helpers pour récupérer la config du site
- `/prisma/schema.prisma` - Schéma de la base de données

---

## 👥 Système de Rôles

### Rôles Disponibles (enum `Role`)
- `ADMIN` - Accès complet au dashboard admin
- `MEMBER` - Membre de l'association (accès intermédiaire)
- `STUDENT` - Étudiant standard

### Vérification des Rôles
```typescript
import { requireAdmin, requireMember, getSession } from '@/app/lib/auth'

// Dans un Server Action ou page
await requireAdmin() // Throw si pas admin
await requireMember() // Throw si pas membre ou admin
const session = await getSession() // Récupère la session
```

---

## 🔧 Fonctionnalités Implémentées

### 1. Mode Maintenance
**Fichiers concernés:**
- `/app/lib/config.ts` - `isMaintenanceMode()` helper
- `/app/components/MaintenancePage.tsx` - Page de maintenance
- `/app/(public)/layout.tsx` - Vérifie maintenance pour pages publiques
- `/app/(dashboard)/layout.tsx` - Bloque étudiants en mode maintenance

**Fonctionnement:**
- Activable depuis Admin > Paramètres > `maintenance_mode`
- Les admins peuvent toujours accéder au site
- Les étudiants voient la page de maintenance

### 2. Gestion des Événements
**Fichiers concernés:**
- `/app/(dashboard)/admin/events/` - CRUD événements
- `/app/(dashboard)/admin/events/[id]/registrations/` - Voir/supprimer inscriptions
- `/app/actions/events.actions.ts` - Server actions

**Fonctionnalités:**
- Créer/modifier/supprimer des événements
- Voir la liste des inscrits par événement
- Supprimer des inscriptions (admin)
- Les étudiants peuvent s'inscrire depuis la page publique

### 3. Système de Cafétéria
**Modèles Prisma:**
- `Product` - Produits avec `costPrice` (prix d'achat)
- `ProductCategory` - Catégories de produits
- `CafeteriaOrder` - Commandes
- `CafeteriaOrderItem` - Items de commande

**Fichiers concernés:**
- `/app/(dashboard)/admin/cafeteria/` - Gestion produits
- `/app/(dashboard)/admin/cafeteria/commandes/` - Gestion commandes
- `/app/(dashboard)/eleve/cafeteria/` - Interface commande étudiant
- `/app/actions/cafeteria.actions.ts` - Server actions

**Statuts de commande (enum `CafeteriaOrderStatus`):**
- `PENDING` → `CONFIRMED` → `PREPARING` → `READY` → `DELIVERED`
- `CANCELLED`

### 4. Intégration SumUp
**Fichiers concernés:**
- `/app/(dashboard)/admin/sumup/` - Dashboard SumUp
- `/app/actions/sumup.actions.ts` - Actions API SumUp

**Configuration requise (table `Config`):**
- `sumup_api_key` - Clé API SumUp
- `sumup_merchant_code` - Code marchand

**Fonctionnalités:**
- Voir les transactions SumUp
- Statistiques (CA, panier moyen, taux de succès)
- **Calcul des bénéfices** basé sur `costPrice` des produits

### 5. Calcul des Bénéfices (Nouveau)
**Fichiers modifiés:**
- `/prisma/schema.prisma` - Ajout champ `costPrice` sur `Product`
- `/app/(dashboard)/admin/cafeteria/ProductForm.tsx` - Champ "Prix d'achat"
- `/app/actions/cafeteria.actions.ts` - Gestion du costPrice
- `/app/actions/sumup.actions.ts` - Fonction `getProfitStats()`
- `/app/(dashboard)/admin/sumup/SumUpDashboard.tsx` - Carte bénéfices

**Calculs effectués:**
- Bénéfice net = Revenus - Coûts
- Marge bénéficiaire = (Bénéfice / Revenus) × 100
- Basé sur les commandes avec statut `DELIVERED`

---

## 📊 Schéma Base de Données (Principaux Modèles)

### User
```prisma
model User {
  id            String    @id @default(cuid())
  email         String    @unique
  name          String?
  password      String
  role          Role      @default(STUDENT)
  avatar        String?
  // Relations...
}
```

### Product
```prisma
model Product {
  id          String   @id @default(cuid())
  name        String
  description String?
  price       Decimal  @db.Decimal(10, 2)
  costPrice   Decimal? @db.Decimal(10, 2)  // Prix d'achat
  image       String?
  stock       Int      @default(0)
  isAvailable Boolean  @default(true)
  isActive    Boolean  @default(true)
  categoryId  String?
  // Relations...
}
```

### Event
```prisma
model Event {
  id           String   @id @default(cuid())
  title        String
  description  String?
  content      String?  @db.Text
  image        String?
  date         DateTime
  endDate      DateTime?
  location     String?
  maxAttendees Int?
  isPublished  Boolean  @default(false)
  // Relations...
}
```

### Config
```prisma
model Config {
  key   String @id
  value String @db.Text
}
```

**Clés de configuration utilisées:**
- `site_name`, `site_description`, `contact_email`
- `maintenance_mode` (true/false)
- `sumup_api_key`, `sumup_merchant_code`

---

## 🚀 Commandes Utiles

```bash
# Installer les dépendances
npm install

# Générer le client Prisma
npx prisma generate

# Appliquer les migrations
npx prisma db push

# Lancer en développement
npm run dev

# Build production
npm run build

# Voir la base de données
npx prisma studio
```

---

## ⚠️ Points d'Attention

1. **Import Auth**: Toujours utiliser `@/app/lib/auth` (pas `@/auth`)

2. **Prisma Client**: Après modification du schema, exécuter:
   ```bash
   npx prisma generate
   npx prisma db push
   ```

3. **Maintenance Mode**: Les admins bypass automatiquement

4. **Calcul Bénéfices**: Nécessite que les produits aient un `costPrice` renseigné

5. **SumUp API**: Nécessite configuration des clés dans Admin > Paramètres

---

## 📝 Dernières Modifications (Session Actuelle)

1. ✅ Système de gestion des inscriptions événements (admin)
2. ✅ Mode maintenance fonctionnel
3. ✅ Blocage étudiants en mode maintenance
4. ✅ Champ `costPrice` pour les produits
5. ✅ Calcul et affichage des bénéfices dans dashboard SumUp

---

## 🔗 Variables d'Environnement (.env)

```env
DATABASE_URL="mysql://user:password@host:port/database"
NEXTAUTH_SECRET="your-secret-key"
NEXTAUTH_URL="http://localhost:3000"
```
