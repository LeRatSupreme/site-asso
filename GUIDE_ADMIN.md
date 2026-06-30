# 📖 Guide de l'administrateur — Site AEIC

> Ce document explique comment utiliser l'espace d'administration du site AEIC. Il est destiné à tous les membres du bureau (président, trésorier, secrétaire) qui doivent gérer le site au quotidien.

---

## 📋 Sommaire

1. [Se connecter à l'admin](#1--se-connecter-à-ladmin)
2. [Le tableau de bord](#2--le-tableau-de-bord)
3. [Gérer les événements](#3--gérer-les-événements)
4. [Gérer les sondages](#4--gérer-les-sondages)
5. [Gérer les promotions](#5--gérer-les-promotions)
6. [La cafétéria (produits & catégories)](#6--la-cafétéria-produits--catégories)
7. [La comptabilité (import SumUp)](#7--la-comptabilité-import-sumup)
8. [Le réapprovisionnement](#8--le-réapprovisionnement)
9. [Le dashboard Analytics](#9--le-dashboard-analytics)
10. [Gérer les utilisateurs & adhésions](#10--gérer-les-utilisateurs--adhésions)
11. [Gérer l'équipe](#11--gérer-léquipe)
12. [Gérer les pages (CMS)](#12--gérer-les-pages-cms)
13. [La bibliothèque de médias](#13--la-bibliothèque-de-médias)
14. [Les paramètres du site](#14--les-paramètres-du-site)
15. [Check-in QR codes aux événements](#15--check-in-qr-codes-aux-événements)
16. [Conseils & bonnes pratiques](#16--conseils--bonnes-pratiques)

---

## 1. 🔑 Se connecter à l'admin

### Accès
- Va sur **https://asso.aremond.ovh/login**
- Entre ton **email** et ton **mot de passe**
- Si tu es administrateur, un bouton **« Admin »** apparaît en haut à droite
- Clique dessus pour accéder à l'espace d'administration

### Sécurité 2FA (authentification à deux facteurs)
- Les administrateurs **doivent** configurer le 2FA (code à 6 chiffres via app Google Authenticator)
- À la première connexion, un QR code s'affiche : scanne-le avec ton téléphone
- À chaque connexion, entre le code à 6 chiffres généré par l'app

### Rôles
| Rôle | Accès |
|------|-------|
| **ADMIN** | Tout l'espace d'administration |
| **TRESORERIE** | Uniquement la comptabilité (dashboard, import, coûts, réappro) |
| **ELEVE** | Aucun accès à l'admin |

### Mot de passe oublié
- Page **« Mot de passe oublié ? »** sur l'écran de connexion
- Un email avec un lien de réinitialisation est envoyé
- L'admin peut aussi réinitialiser le mot de passe d'un utilisateur depuis **Utilisateurs → Reset MDP**

---

## 2. 📊 Le tableau de bord

Accessible via **Admin → Tableau de bord** (ou `/admin`).

### Ce qu'il affiche
| Carte | Description |
|-------|-------------|
| **Membres actifs** | Nombre total d'utilisateurs actifs |
| **À jour de cotisation** | Membres ayant payé leur cotisation pour la saison en cours |
| **Événements publiés** | Nombre d'événements visibles sur le site |
| **CA ce mois** | Chiffre d'affaires du mois (basé sur les ventes SumUp importées) |
| **Bénéfice ce mois** | Bénéfice net = CA − coûts de revient |

### Journal d'audit
En bas du tableau de bord, le **journal d'audit** liste les dernières actions :
- Qui a créé/modifié/supprimé quoi et quand
- Exemples : `event.create`, `user.role_change`, `compta.import`, `product.update`

> 💡 Le journal garde une trace de **toutes les actions sensibles**. Il est utile pour comprendre qui a fait quoi en cas de problème.

---

## 3. 📅 Gérer les événements

Accessible via **Admin → Contenu → Événements** (ou `/admin/events`).

### Créer un événement
1. Clique **« + Nouvel événement »**
2. Remplis le formulaire :

| Champ | Description | Exemple |
|-------|-------------|---------|
| **Titre** | Nom de l'événement | `Soirée d'intégration` |
| **Slug (URL)** | URL de la page | `soiree-integration` |
| **Catégorie** | Type d'événement | `Soirée`, `Tournoi / LAN`, `Conférence` |
| **Extrait** | Résumé court (affiché sur les cartes + Google) | `Le rendez-vous de rentrée...` |
| **Description** | Texte complet (HTML autorisé) | `<p>Détails de l'événement...</p>` |
| **Date et heure** | Quand | `12/09/2026 20:00` |
| **Lieu** | Où | `Campus de Calais — Amphi A` |
| **Afficher une carte** | Active une carte interactive (géocodage auto du lieu) | ✅ |
| **Prix** | Prix en € (vide = gratuit) | `5,00` |
| **Capacité max** | Nombre max de places (vide = illimité) | `50` |
| **Image** | URL d'une image de couverture | `uploads/photo.jpg` |
| **Lien SumUp** | URL de paiement SumUp | `https://pay.sumup.com/...` |
| **Mis en avant** | Affiché en priorité sur l'accueil | ✅ |
| **Publié** | Visible sur le site public | ✅ |

3. Clique **« Créer l'événement »**

### Modifier un événement
- Dans la liste, clique l'icône ✏️ à côté de l'événement
- Modifie les champs souhaités → **Enregistrer**

### Supprimer un événement
- Clique l'icône 🗑️ → une fenêtre de confirmation s'affiche → **Supprimer**
- ⚠️ La suppression est **définitive** (les inscriptions sont aussi supprimées)

### Gérer les inscriptions
- Clique 📋 à côté d'un événement → tu vois la liste des inscrits
- Tu peux voir leurs choix (menus, options) et **exporter en CSV**
- Si l'événement a une **capacité max** et est complet → la **liste d'attente** s'affiche en bas
- Tu peux **promouvoir** manuellement quelqu'un de la file d'attente

### Liste d'attente (automatique)
Quand un événement est **complet** :
- Les nouvelles inscriptions vont en **file d'attente** automatiquement
- Si quelqu'un se désinscrit → le premier de la file est **promu** automatiquement
- Il reçoit un **email** + une **notification** « Une place s'est libérée ! »

---

## 4. 📊 Gérer les sondages

Accessible via **Admin → Contenu → Sondages** (ou `/admin/sondages`).

### Créer un sondage
1. Clique **« + Nouveau sondage »**
2. Remplis :
   - **Titre** : `Chocolatine ou pain au chocolat ?`
   - **Description** : texte optionnel
   - **Options** : clique **« + Ajouter une option »** pour chaque choix
     - `Chocolatine`
     - `Pain au chocolat`
   - **Choix multiple** : ✅ si l'utilisateur peut voter pour plusieurs options
   - **Publié** : ✅ pour le rendre visible
3. **Enregistrer**

### Comment ça marche pour les élèves
- L'élève va sur **/sondages** → voit le sondage → clique **« Participer »**
- Il vote (radio = 1 choix, checkbox = plusieurs)
- Après le vote → il voit les **résultats en direct** (barres de progression + %)
- Il ne peut voter qu'**une seule fois**

### Résultats
- Accessibles par tous (même sans voter)
- Barres de progression colorées avec 🏆 pour l'option gagnante
- Compteur de votants

---

## 5. 🏷️ Gérer les promotions

Accessible via **Admin → Contenu → Promotions** (ou `/admin/promotions`).

### Créer une promo
1. Clique **« + Nouvelle promotion »**
2. Remplis :
   - **Titre** : `Coca à 0,80€ au lieu de 1€`
   - **Description** : `Promo spéciale rentrée`
   - **Badge** : `PROMO`, `NOUVEAU`, `-20%`...
   - **Ancien prix** : `1,00`
   - **Nouveau prix** : `0,80`
   - **Date de début/fin** : optionnel
   - **Active** : ✅ pour l'afficher sur l'accueil
3. **Enregistrer**

### Affichage
- Les promos **actives** apparaissent sur la page d'accueil dans la section **« Promos & ventes spéciales »**
- Si aucune promo active → message « Pas de promo en ce moment »

---

## 6. ☕ La cafétéria (produits & catégories)

Accessible via **Admin → Cafétéria → Produits** (ou `/admin/cafeteria`).

### Produits
C'est ici que tu gères la **carte** affichée sur la page d'accueil.

#### Ajouter un produit
1. Clique **« + Nouveau produit »**
2. Remplis :
   - **Nom** : `Red Bull`
   - **Description** : `Boisson énergisante`
   - **Prix de vente** : `1,50`
   - **Catégorie** : `Boissons`
   - **Image (URL)** : `uploads/photo.jpg` (optionnel — sinon un emoji auto est affiché)
   - **Stock** : nombre d'unités en stock (pour le réappro)
   - **Disponible** : ✅ si en stock
   - **Actif** : ✅ si affiché sur le site
3. **Enregistrer**

#### Emojis automatiques
Le site attribue automatiquement un emoji selon le nom du produit :
- Coca, Fanta, Oasis → 🥤/🧃
- Eau, Cristaline → 💧
- Monster, Red Bull → ⚡
- Bueno, KitKat, Mars → 🍫
- Chips → 🍟
- Bonbon → 🍬

Si tu ajoutes une **image** au produit → l'image remplace l'emoji.

### Catégories
**Admin → Cafétéria → Catégories** :
- Crée/modifie les catégories (Boissons, Snacks, Spécial)
- L'**ordre** détermine l'affichage des onglets sur l'accueil
- Une catégorie **inactive** n'apparaît pas

---

## 7. 💰 La comptabilité (import SumUp)

### A. Importer un rapport SumUp
**Admin → Comptabilité → Importer CSV** :

1. Récupère ton rapport SumUp (format CSV)
   - Sur l'app SumUp : **Reports** → sélectionne la période → **Export CSV**
2. Sur le site : **Importer CSV** → **choisis le fichier** → **Importer**
3. Le système :
   - Parse le CSV (dates, prix, moyen de paiement)
   - **Déduplique** (si tu importes 2× le même fichier = 0 doublon)
   - Affiche un rapport : `X nouvelles lignes, Y ignorées`
4. Les ventes sont disponibles dans le **Journal**, **Dashboard**, **Produits**, etc.

> ⚠️ Le CSV doit être au format **SumUp** (colonnes : Date, Réf. transaction, Description, Prix TTC, etc.)

### B. Mapping des libellés
**Admin → Comptabilité → Mapping libellés** :

SumUp enregistre parfois le même produit sous des noms différents (Bueno, Bueno_white, etc.).
- Le **mapping** associe ces variantes à un **nom canonique** unique
- Bouton **« Auto-détecter les doublons »** → propose automatiquement un mapping
- Vérifie → **Appliquer**

### C. Coûts de revient
**Admin → Comptabilité → Coûts de revient** :

Pour calculer le **bénéfice réel**, tu dois saisir le **prix d'achat** de chaque produit :
1. Recherche le produit (champ autocomplété)
2. Saisis le **coût d'achat unitaire** (ex: Bueno → `0,60€`)
3. Ajoute le **fournisseur** (Metro, Carrefour...)
4. **Enregistrer**

> 💡 Le bénéfice = Prix de vente − Coût d'achat. Sans coût saisi → bénéfice = 100% (faux).

### D. Journal des ventes
**Admin → Comptabilité → Journal ventes** :
- Toutes les ventes importées, **filtrables** (mois, catégorie, produit, paiement)
- **Export CSV** disponible
- **Totaux** en bas (CA, quantité, bénéfice)

### E. Bénéfice par produit
**Admin → Comptabilité → Produits** :
- **Recherche** + **tri** par colonne (CA, bénéfice, marge)
- **Filtre par catégorie**

### F. Bénéfice par catégorie
**Admin → Comptabilité → Catégories** :
- Vue d'ensemble par catégorie (Boisson, Nourriture, Spécial)
- **Cartes KPI** : CA, bénéfice, marge, % du CA total

---

## 8. 📦 Le réapprovisionnement

**Admin → Comptabilité → Réappro** (ou `/admin/compta/reappro`).

### Principe
La page calcule **combien racheter** de chaque produit, basé sur les ventes réelles :
- **Conso / jour** : ventes moyennes par jour d'ouverture (lun-ven)
- **Conso / semaine** : × 5 jours
- **Conso / mois** : moyenne mobile 3 mois
- **Besoin** : conso estimée sur la période choisie
- **À commander** : besoin − stock actuel

### Comment l'utiliser
1. Choisis la **période** à couvrir (1 semaine, 2 semaines, 1 mois, 2 mois, 3 mois)
2. Saisis le **stock actuel** de chaque produit dans le champ
3. Clique **« Enregistrer les stocks »**
4. La colonne **« À commander »** et le **total** se recalculent

### États
| Badge | Signification |
|-------|---------------|
| **À définir** | Stock non renseigné (saisis-le) |
| **À racheter** | Stock faible ou nul |
| **OK** | Stock suffisant pour la période |

> 💡 La cafétéria est ouverte **du lundi au vendredi** → les calculs sont basés sur les jours d'ouverture (≈ 22/mois).

---

## 9. 📈 Le dashboard Analytics

**Admin → Comptabilité → Analytics** (ou `/admin/analytics`).

### Filtres globaux
En haut de la page :
- **Période** : 7j / 30j / 3 mois / 6 mois / 12 mois / année / dates personnalisées
- **Granularité** : Jour / Semaine / Mois (affecte les graphiques de tendance)
- **Catégorie** : filtrer par Boisson, Nourriture, etc.
- **Paiement** : filtrer par Carte ou Espèces

### KPI Cards (6 indicateurs)
| Carte | Description |
|-------|-------------|
| **CA TTC** | Chiffre d'affaires + variation vs période précédente |
| **Bénéfice net** | CA − coûts + marge % |
| **Volume vendu** | Nombre d'unités vendues |
| **Panier moyen** | CA / nb transactions |
| **Transactions** | Nombre de lignes de vente |
| **Nouveaux membres** | Inscrits sur la période |

> Les flèches ↑/↓ indiquent la variation par rapport à la période précédente.

### Graphiques
1. **Évolution CA + Bénéfice** : barres (CA) + ligne (bénéfice) dans le temps
2. **Top 10 produits** : produits qui rapportent le plus (cliquable → page produits)
3. **Répartition par catégorie** : doughnut avec CA total au centre
4. **Répartition des paiements** : doughnut Carte vs Liquide (avec %)
5. **Heatmap jour × heure** : grille 7 jours × 24h avec intensité du CA (survol = tooltip)
6. **Activité de l'asso** : inscriptions / membres / votes sur 6 mois

### Tableau récapitulatif
- Tous les produits avec : qté, CA, coût, bénéfice, marge %
- **Tri par colonne** (clic sur l'en-tête)
- **Export CSV**

### Insights (analyses auto)
- 🏆 **Produit star** : le plus vendu
- 📈 **Plus forte croissance** : produit qui a le plus progressé
- ⚠️ **Alerte marge** : produit avec une marge faible (< 10%)
- 🕐 **Meilleur jour** : jour de la semaine qui rapporte le plus

---

## 10. 👥 Gérer les utilisateurs & adhésions

### Utilisateurs
**Admin → Système → Utilisateurs** (ou `/admin/users`).

| Action | Description |
|--------|-------------|
| **Changer le rôle** | ELEVE → ADMIN ou TRESORERIE |
| **Activer/Désactiver** | Bloque l'accès au site |
| **Reset MDP** | Génère un mot de passe temporaire (envoyé par email) |
| **Supprimer** | Anonymise les données (RGPD) — les commandes sont conservées |
| **Marquer payée** | Marque la cotisation comme payée pour la saison |

> ⚠️ Tu ne peux pas :
> - Supprimer ton propre compte depuis l'admin
> - Supprimer/rétrograder le dernier administrateur
> - Chaque action est **journalisée** (audit log)

### Adhésions / Cotisations
**Admin → Système → Adhésions** (ou `/admin/memberships`).

- Liste de toutes les adhésions par saison
- **Filtre par saison** (2025-2026, 2026-2027...)
- Statuts : **Payée** (vert) / **En attente** (ambre) / **Expirée** (gris)
- Bouton **« Marquer payée »** avec montant

---

## 11. 👥 Gérer l'équipe

**Admin → Contenu → Équipe** (ou `/admin/team`).

### Ajouter un membre
1. Clique **« + Nouveau membre »**
2. Remplis : prénom, nom, rôle (Président, Trésorier...), pôle, bio, photo (URL)
3. **Mis en avant** : ✅ pour le bureau restreint (affiché en premier)
4. **Actif** : ✅ pour l'afficher sur la page /team
5. **Enregistrer**

### Photo
- URL d'une image uploadée dans **Médias**
- Si pas de photo → un placeholder est affiché

---

## 12. 📄 Gérer les pages (CMS)

**Admin → Contenu → Pages** (ou `/admin/pages`).

### Pages existantes
| Slug | Page |
|------|------|
| `presentation` | L'association (/presentation) |
| `team` | Équipe (/team) — *(redirigée vers le système d'équipe)* |
| `legal` | Mentions légales (/legal) |
| `privacy` | Politique de confidentialité (/privacy) |
| `cgu` | Conditions générales d'utilisation (/cgu) |

### Modifier une page
1. Clique sur la page → formulaire
2. Modifie le **titre**, **contenu HTML**, **méta** (SEO)
3. **Publiée** : ✅ pour l'afficher
4. **Enregistrer**

> 💡 Le contenu est du **HTML** : tu peux utiliser `<h2>`, `<p>`, `<ul>`, `<strong>`, `<a>` etc.

---

## 13. 🖼️ La bibliothèque de médias

**Admin → Contenu → Médias** (ou `/admin/media`).

### Uploader une image
1. Clique dans la zone **« Clique ou dépose une image »** (ou glisse-dépose)
2. Ajoute un **texte alternatif** (accessibilité)
3. Clique **« Téléverser »**

### Utiliser une image
- Clique **« Copier l'URL »** → l'URL est dans le presse-papier
- Colle cette URL dans le champ **Image** d'un événement, produit, ou membre d'équipe

### Format acceptés
JPG, PNG, GIF, WebP, SVG — **5 Mo max**

---

## 14. ⚙️ Les paramètres du site

**Admin → Système → Paramètres** (ou `/admin/settings`).

### Sections

#### ⚙️ Général
- **Nom du site** : affiché partout (navbar, footer, emails)
- **Description** : sous-titre de l'accueil + méta SEO
- **Email de contact** : pour les demandes RGPD
- **Logo** : URL du logo

#### 📍 Contact & Localisation
- **Adresse** : affichée sur la carte
- **Latitude / Longitude** : position du marqueur sur la carte

#### 📧 Emails / SMTP
- **Clé API Brevo** : pour l'envoi d'emails (recommandé)
- **Adresse d'expédition** : email expéditeur (utiliser un domaine vérifié)
- **Nom affiché** : « AEIC »
- **Paramètres SMTP** : alternative à l'API Brevo
- **Bouton « Envoyer un e-mail de test »** : pour vérifier la configuration

#### 💳 SumUp
- **Lien de paiement par défaut** : URL SumUp
- **Activer les paiements** : ✅ pour afficher les boutons « Payer en ligne »

#### 🎛️ Fonctionnalités
- **Mode maintenance** : bloque le site public (admin garde l'accès)
- **Commandes en ligne** : active/désactive les commandes (si utilisées)
- **Inscriptions aux événements** : active/désactive les inscriptions

#### 📱 Discord
- **URL Webhook Discord** : pour les annonces automatiques
- **Activer** : ✅ pour envoyer un message Discord à chaque nouvel événement/sondage

---

## 15. 📱 Check-in QR codes aux événements

### Comment ça marche
Quand un élève s'inscrit à un événement :
- Un **QR code unique** est généré
- Il est visible sur la page de l'événement (si inscrit)

### Le jour de l'événement
1. Va sur **Admin → Événements → 📋 (Inscrits)**
2. Clique **« Ouvrir le check-in »**
3. La page de scan s'ouvre avec un champ de saisie
4. **Scanne le QR code** de chaque participant (ou saisis le token manuellement)
5. Le système affiche : `✅ Prénom Nom — Présent`
6. Les doublons sont détectés : `⚠️ Déjà checké`

### Badge de présence
Sur la page des inscriptions :
- ✅ vert = présent
- ⬜ gris = absent
- Tu peux basculer manuellement avec un bouton

---

## 16. 💡 Conseils & bonnes pratiques

### Importer les ventes SumUp
- **Au moins une fois par mois** (ou plus souvent)
- Importe le rapport SumUp dans **Comptabilité → Importer CSV**
- Vérifie les **libellés non mappés** (aliases)
- Saisis les **coûts de revient** pour les nouveaux produits

### Garder le site à jour
- **Avant chaque événement** : crée l'événement + vérifie la catégorie + carte activée
- **Après chaque événement** : ajoute les photos dans Médias
- **Régulièrement** : mets à jour les coûts de revient si les prix d'achat changent

### Cotisations
- En début d'année : crée les adhésions pour les nouveaux membres
- Marque « Payée » avec le montant dès réception du paiement
- Le tableau de bord affiche le nombre de membres à jour

### Emails
- Vérifie régulièrement que les emails partent (test depuis Paramètres)
- Les élèves reçoivent automatiquement :
  - Leur mot de passe à l'inscription
  - Un rappel 24h avant un événement
  - Un rappel 1h avant un événement
  - Une confirmation de changement de mot de passe

### Sauvegardes
- Une **sauvegarde automatique** tourne chaque nuit à 3h (cron)
- En cas de problème : contacte l'administrateur technique (Remond Adrien)

### Rôles & sécurité
- Ne donne le rôle **ADMIN** qu'aux membres du bureau de confiance
- Le rôle **TRESORERIE** suffit pour gérer la comptabilité
- **Change ton mot de passe** régulièrement (Mon compte → Changer mon mot de passe)
- Ne partage **jamais** ton mot de passe

---

## ❓ Aide & support

| Problème | Contact |
|----------|---------|
| Bug technique | Remond Adrien (développeur) |
| Question comptabilité | Trésorier |
| Accès / mot de passe | Un administrateur (Reset MDP) |
| Email ne part pas | Vérifier Paramètres → Emails/SMTP |

---

*Document maintenu par Remond Adrien — © 2026 AEIC · Développé par Remond Adrien*
