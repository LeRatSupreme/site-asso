# PROMPT — GÉNÉRATION V1 COMPLÈTE (NON MVP)
## Site web associatif FULL ADMINISTRABLE — Next.js 14 App Router

---

## 🚨 INSTRUCTIONS CRITIQUES (NON NÉGOCIABLES)

- ❌ AUCUN MVP
- ❌ AUCUNE fonctionnalité codée en dur
- ❌ AUCUNE donnée modifiable hors panel admin
- ❌ AUCUNE configuration via fichier statique
- ❌ AUCUN backend séparé

👉 **TOUT doit être modifiable depuis le PANEL ADMIN**

---

## 🎭 RÔLE À ADOPTER

Tu es un **Lead Developer Fullstack Senior**, expert en :
- Next.js 16+ App Router
- Backoffice / CMS custom
- Sécurité & permissions
- Projets associatifs en production

Tu développes **comme pour une vraie association**, avec passage de relais à d’autres équipes.

---

## 🧠 OBJECTIF GLOBAL

Créer un **site web associatif FULLSTACK**, où :

- **LES ADMINS PILOTENT TOUT**
- Les élèves / utilisateurs ne font QUE consulter et s’inscrire
- Le site est **réutilisable, maintenable et évolutif**

---

## 🧱 ARCHITECTURE — VERROUILLÉE

### ⚠️ INTERDICTIONS FORMELLES

- ❌ Backend Express / Nest
- ❌ Firebase-only
- ❌ Contenu codé en dur (textes, images, liens)
- ❌ Modifications hors panel admin
- ❌ Logique métier côté client

---

### ✅ ARCHITECTURE OBLIGATOIRE

- Next.js 14+ (App Router)
- Fullstack intégré
- Server Components
- Server Actions
- Middleware Next.js
- TypeScript strict
- MySQL
- Auth.js (NextAuth v5)
- Tailwind CSS

---

## 🗂️ STRUCTURE DES DOSSIERS — OBLIGATOIRE

```txt
app/
├─ (public)/
│  ├─ page.tsx
│  ├─ events/page.tsx
│  ├─ presentation/page.tsx
│  ├─ team/page.tsx
│  ├─ legal/page.tsx
│  └─ privacy/page.tsx
│
├─ (auth)/
│  ├─ login/page.tsx
│  └─ register/page.tsx
│
├─ (dashboard)/
│  ├─ eleve/page.tsx
│  ├─ admin/page.tsx
│  ├─ admin/events/page.tsx
│  ├─ admin/orders/page.tsx
│  ├─ admin/users/page.tsx
│  ├─ admin/pages/page.tsx
│  ├─ admin/settings/page.tsx
│  └─ admin/media/page.tsx
│
├─ actions/
│  ├─ events.actions.ts
│  ├─ registrations.actions.ts
│  ├─ orders.actions.ts
│  ├─ users.actions.ts
│  ├─ photos.actions.ts
│  ├─ pages.actions.ts
│  └─ settings.actions.ts
│
├─ api/
│  └─ upload/route.ts
│
├─ lib/
│  ├─ auth.ts
│  ├─ prisma.ts
│  ├─ roles.ts
│  ├─ permissions.ts
│  └─ config.ts
│
├─ components/
│  ├─ EventCard.tsx
│  ├─ OrderForm.tsx
│  ├─ DashboardLayout.tsx
│  ├─ Navbar.tsx
│  ├─ AdminSidebar.tsx
│  └─ RichTextEditor.tsx
│
├─ middleware.ts
└─ layout.tsx
🔐 AUTHENTIFICATION & RÔLES
ADMIN
ELEVE
Règles STRICTES
Seul un ADMIN peut :
créer / modifier / supprimer du contenu
gérer les paramètres globaux
uploader des images
Middleware protège TOUT le dashboard
Aucun contrôle côté client seul n’est accepté
🗃️ MODÈLE DE DONNÉES — ÉTENDU & ADMIN-FIRST
User
- id
- name
- email
- password / provider
- role
- createdAt

Event
- id
- title
- description
- image
- date
- location
- sumupLink
- isPublished
- createdAt

EventRegistration
- id
- userId
- eventId
- createdAt

Order
- id
- userId
- type (PANINI | RACLETTE | PIZZA)
- option
- createdAt

Photo
- id
- eventId
- url
- createdAt

Page
- id
- slug
- title
- content (rich text)
- isPublished
- updatedAt

Setting
- id
- key
- value
🧠 ADMIN PANEL — OBLIGATOIRE ET COMPLET
🧑‍💻 Dashboard Admin
Vue globale (stats simples)
Accès rapide aux modules
🎉 Gestion des événements
CRUD complet
Publication / dépublication
Gestion des liens SumUp
Upload et gestion des photos
Contrôle affichage public
🍕 Gestion des commandes
Voir toutes les commandes
Filtres (type, date)
Export CSV
Activation / désactivation des commandes
👥 Gestion des utilisateurs
Liste utilisateurs
Changement de rôle
Désactivation de compte
📄 Gestion des pages (CMS)
Modifier :
Accueil
Présentation
Équipe
Mentions légales
RGPD
Éditeur riche
Publication contrôlée
⚙️ Paramètres globaux
Nom de l’association
Liens réseaux sociaux
Liens SumUp par défaut
Images globales
Configuration future-proof
🚀 LIVRABLES OBLIGATOIRES
Tu dois fournir :
Architecture expliquée
Modèle DB complet 
Panel admin fonctionnel
Middleware & permissions
Server Actions sécurisées
CMS interne opérationnel
Code prêt production
🧨 RÈGLES FINALES
Tout est administrable
Rien n’est figé dans le code
Aucune donnée en dur
Projet transmissible à d’autres équipes
Niveau production réelle
🎯 OBJECTIF FINAL :
V1 COMPLÈTE — FULL ADMIN — CMS READY — PRODUCTION READY
