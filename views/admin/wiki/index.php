<?php

declare(strict_types=1);

/**
 * Wiki / Guide de l'administrateur — intégré au site.
 *
 * @var array<string,mixed> $user
 */

$sections = [
    'getting-started' => [
        'icon' => '🚀',
        'title' => 'Démarrage',
        'color' => '#48bdd3',
        'items' => [
            'Comment se connecter ?' => 'Va sur https://asso.aremond.ovh/login → entre ton email et mot de passe. Si tu es admin, un bouton « Admin » apparaît en haut à droite.',
            'Le 2FA (authentification à 2 facteurs)' => 'Les administrateurs doivent configurer le 2FA. À la première connexion, scanne le QR code avec Google Authenticator (ou équivalent). À chaque connexion ultérieure, entre le code à 6 chiffres généré par l\'app.',
            'Mot de passe oublié' => 'Sur la page de connexion, clique « Mot de passe oublié ? ». Un email avec un lien de réinitialisation est envoyé. Un admin peut aussi faire « Reset MDP » depuis Utilisateurs.',
            'Les rôles' => 'ADMIN = accès complet. TRESORERIE = uniquement la comptabilité. ELEVE = pas d\'accès admin. Seul un ADMIN peut changer les rôles.',
            'Changer mon mot de passe' => 'Mon compte → Mes données → « Changer mon mot de passe ». Un email de confirmation est envoyé. 8 caractères minimum avec 1 lettre + 1 chiffre.',
        ],
    ],
    'dashboard' => [
        'icon' => '📊',
        'title' => 'Tableau de bord',
        'color' => '#6150aa',
        'items' => [
            'Que montre le tableau de bord ?' => '5 indicateurs : membres actifs, membres à jour de cotisation, événements publiés, CA du mois (basé sur les ventes SumUp importées), bénéfice du mois.',
            'Journal d\'audit' => 'En bas du tableau de bord, liste toutes les actions sensibles : qui a créé/modifié/supprimé quoi et quand. Utile en cas de problème.',
            'Les chiffres sont à 0' => 'Si le CA est à 0 → tu n\'as pas importé de rapport SumUp ce mois-ci. Va dans Comptabilité → Importer CSV.',
        ],
    ],
    'events' => [
        'icon' => '📅',
        'title' => 'Événements',
        'color' => '#48bdd3',
        'items' => [
            'Créer un événement' => 'Admin → Événements → « + Nouvel événement ». Remplis : titre, slug (URL), catégorie, extrait (résumé court), description (HTML), date/heure, lieu.',
            'Catégories d\'événements' => 'Les événements sont groupés par catégorie sur la page /events. Catégories possibles : Soirée, Afterwork, Barbecue, Tournoi/LAN, Conférence, Sortie, Atelier, Nuit de l\'Info, Hackathon, Rentrée, Autre.',
            'Capacité max et liste d\'attente' => 'Si tu mets une capacité max (ex: 50), les inscriptions au-delà vont en file d\'attente automatiquement. Quand quelqu\'un se désinscrit → le premier de la file est promu + reçoit un email automatique.',
            'Carte interactive' => 'Coche « Afficher une carte du lieu » → l\'adresse est géocodée automatiquement et une carte OpenStreetMap s\'affiche sur la page de l\'événement.',
            'Prix et paiement SumUp' => 'Mets un prix (vide = gratuit). Colle un lien SumUp dans « Lien de paiement ». Le bouton « Payer en ligne » apparaîtra sur la page.',
            'Mettre en avant' => 'Coche « Mis en avant » → l\'événement apparaît en priorité sur l\'accueil.',
            'Publier / dépublier' => 'Coche « Publié » pour le rendre visible. Décoche pour le masquer (brouillon).',
            'Voir les inscriptions' => 'Clique 📋 à côté d\'un événement → liste des inscrits avec nom, date d\'inscription, choix (menus/options). Export CSV disponible.',
            'Modifier un événement' => 'Clique ✏️ → modifie les champs → « Enregistrer ».',
            'Supprimer un événement' => 'Clique 🗑️ → confirmation → suppression définitive (inscriptions supprimées aussi).',
        ],
    ],
    'checkin' => [
        'icon' => '📱',
        'title' => 'Check-in QR',
        'color' => '#f59e0b',
        'items' => [
            'Comment ça marche ?' => 'Quand un élève s\'inscrit → un QR code unique est généré automatiquement. Il est visible sur la page de l\'événement (si l\'élève est connecté et inscrit).',
            'Le jour de l\'événement' => 'Admin → Événements → 📋 → « Ouvrir le check-in ». Scanne le QR de chaque participant avec la caméra du téléphone ou saisis le token manuellement → Entrée.',
            'Badge de présence' => 'Après scan : ✅ vert = présent. Si déjà scanné : ⚠️ « Déjà checké ». Tu peux aussi basculer manuellement présent/absent.',
            'QR ne marche pas' => 'L\'élève doit se désinscrire puis se réinscrire pour générer un nouveau QR (si le token a expiré).',
        ],
    ],
    'sondages' => [
        'icon' => '📊',
        'title' => 'Sondages',
        'color' => '#6150aa',
        'items' => [
            'Créer un sondage' => 'Admin → Sondages → « + Nouveau ». Titre + description + options (bouton « + Ajouter une option »). Choix unique (radio) ou multiple (checkbox). Publier.',
            'Comment votent les élèves' => 'L\'élève va sur /sondages → clique « Participer » → sélectionne sa réponse → « Voter ». Une seule fois. Après vote → résultats visibles.',
            'Résultats en direct' => 'Barres de progression avec % pour chaque option. 🏆 sur l\'option gagnante. « ✓ votre choix » s\'affiche sur l\'option que l\'élève a choisie.',
            'Fermer un sondage' => 'Dépublie-le (ou mets une date de clôture). Les résultats restent visibles.',
        ],
    ],
    'cafeteria' => [
        'icon' => '☕',
        'title' => 'Cafétéria (produits & carte)',
        'color' => '#22c55e',
        'items' => [
            'Ajouter un produit' => 'Admin → Cafétéria → Produits → « + Nouveau ». Remplis : nom, description, prix de vente, catégorie, image (optionnel), stock, disponible, actif.',
            'Le menu sur l\'accueil' => 'Les produits actifs + disponibles apparaissent dans « Notre carte » sur la page d\'accueil, organisés par catégorie avec onglets.',
            'Emojis automatiques' => 'Le site attribue un emoji selon le nom (Coca→🥤, Bueno→🍫, Monster→⚡, Eau→💧, Chips→🍟...). Si tu ajoutes une image → elle remplace l\'emoji.',
            'Ajouter une image à un produit' => 'Upload une image dans Médias → copie l\'URL → colle-la dans le champ « Image » du produit.',
            'Gérer les catégories' => 'Admin → Cafétéria → Catégories. Crée/modifie les catégories (Boissons, Snacks, Spécial). L\'ordre détermine l\'affichage des onglets sur l\'accueil.',
            'Activer/Désactiver un produit' => 'Produit « Actif » = visible sur le site. « Disponible » = en stock. Désactive un produit épuisé pour le masquer du menu.',
        ],
    ],
    'promotions' => [
        'icon' => '🏷️',
        'title' => 'Promotions',
        'color' => '#ef4444',
        'items' => [
            'Créer une promo' => 'Admin → Promotions → « + Nouvelle ». Titre, description, badge (PROMO, -20%, NOUVEAU...), ancien prix, nouveau prix, dates (optionnel). Active.',
            'Affichage sur l\'accueil' => 'Les promos actives apparaissent dans « Promos & ventes spéciales ». Ancien prix barré, nouveau prix en gros teal, badge coloré.',
            'Désactiver une promo' => 'Décoche « Active » ou mets une date de fin. La promo disparaît de l\'accueil.',
        ],
    ],
    'compta-import' => [
        'icon' => '📥',
        'title' => 'Comptabilité — Import SumUp',
        'color' => '#48bdd3',
        'items' => [
            'Récupérer le rapport SumUp' => 'Sur l\'app SumUp : Reports → sélectionne la période (mois) → Export CSV. Le fichier contient : date, description produit, prix, moyen de paiement, etc.',
            'Importer le CSV' => 'Admin → Comptabilité → Importer CSV → choisis le fichier → « Importer ». Le système parse, normalise (Carte/Liquide) et déduplique.',
            'Déduplication automatique' => 'Si tu réimportes le même fichier → 0 doublon (clé unique sur transaction_ref + date + produit). Tu peux importer sans crainte.',
            'Montant personnalisé' => 'Les ventes « Montant personnalisé » (saisies libres à la caisse) sont comptées dans le CA mais exclues du bénéfice (pas de produit associé).',
            'Fréquence recommandée' => 'Au moins une fois par mois. Plus tu importes souvent, plus les statistiques sont précises (moyennes mobiles).',
        ],
    ],
    'compta-aliases' => [
        'icon' => '🔗',
        'title' => 'Comptabilité — Mapping libellés',
        'color' => '#6150aa',
        'items' => [
            'Pourquoi mapper ?' => 'SumUp enregistre parfois le même produit sous des noms différents (Bueno, Bueno_white, Coca_cherry, Coca cherry...). Le mapping les fusionne en un seul produit canonique.',
            'Auto-détecter les doublons' => 'Clique « Auto-détecter les doublons » → le système propose un mapping automatique basé sur la similarité des noms. Vérifie → « Appliquer ».',
            'Conséquences' => 'Après mapping : les stats sont fusionnées (une seule ligne Bueno au lieu de Bueno + Bueno_white), les coûts de revient ne sont à saisir qu\'une fois, les marges sont justes.',
            'File à classer' => 'La page « Mapping libellés » montre les libellés non encore mappés (file à classer). Rattache-les un par un ou via l\'auto-détection.',
        ],
    ],
    'compta-costs' => [
        'icon' => '💸',
        'title' => 'Comptabilité — Coûts de revient',
        'color' => '#f59e0b',
        'items' => [
            'Pourquoi saisir les coûts ?' => 'Le bénéfice = prix de vente − coût d\'achat. Sans coût saisi → bénéfice affiché à 100% (faux). Avec coût → marge réelle calculée partout (Analytics, Produits, Journal).',
            'Comment saisir un coût' => 'Comptabilité → Coûts de revient → recherche le produit (autocomplété) → saisis le coût unitaire (ex: Bueno = 0,60€) → fournisseur (Metro, Carrefour...) → « Enregistrer ».',
            'Lots datés' => 'Si tu achètes à des prix différents selon le fournisseur/période → crée un nouveau lot avec une date de début. Le système applique automatiquement le bon lot selon la date de chaque vente.',
            'Clôturer un lot' => 'Quand le prix change → clique « Clôturer » sur l\'ancien lot et crée-en un nouveau. L\'ancien garde ses dates, le nouveau prend le relais.',
            'Quels produits ont un coût ?' => 'Dans la page Coûts de revient, les produits avec un lot actif ont un badge vert « Lot en cours ». Les autres ont « Aucun coût » (ambre).',
        ],
    ],
    'reappro' => [
        'icon' => '📦',
        'title' => 'Réapprovisionnement',
        'color' => '#22c55e',
        'items' => [
            'Principe' => 'La page calcule combien racheter de chaque produit, basé sur les ventes réelles (moyenne mobile 3 mois) et les jours d\'ouverture (lun-ven, ≈22j/mois).',
            'Utilisation' => 'Choisis la période à couvrir (1 semaine, 2 semaines, 1 mois, 2 mois, 3 mois). Saisis le stock actuel dans le champ. Clique « Enregistrer les stocks ».',
            'Colonne « À commander »' => 'Besoin sur la période − stock saisi. Si 0 → stock suffisant (badge OK). Si > 0 → à racheter. Le total en bas somme tout.',
            'États' => 'À définir (gris) = stock non saisi. À racheter (ambre) = stock faible/nul. OK (vert) = stock suffisant.',
            'Jours d\'ouverture' => 'Le CAF est ouvert lundi au vendredi (5j/sem). Les calculs sont basés sur ces jours, pas sur 7j. Conso/jour = moyenne mensuelle ÷ 22.',
        ],
    ],
    'analytics' => [
        'icon' => '📈',
        'title' => 'Dashboard Analytics',
        'color' => '#48bdd3',
        'items' => [
            'Filtres globaux' => 'Période (7j à 12 mois + dates personnalisées). Granularité (jour/semaine/mois). Catégorie (Boisson, Nourriture...). Paiement (Carte, Liquide). Les filtres sont partageables par URL.',
            '6 KPI Cards' => 'CA TTC, Bénéfice net (+marge%), Volume vendu, Panier moyen, Transactions, Nouveaux membres. Chaque carte montre la variation ↑/↓ vs période précédente.',
            'Heatmap jour × heure' => 'Grille 7 jours × 24h. Intensité du CA en couleur. Survol d\'une case → tooltip avec CA de l\'heure + total du jour.',
            'Top 10 produits' => 'Graphique en barres horizontales. Cliquable → redirige vers la page Produits.',
            'Répartition paiements' => 'Doughnut Carte vs Liquide avec % au centre + dans la légende.',
            'Tableau récapitulatif' => 'Tous les produits avec qté, CA, coût, bénéfice, marge. Tri par colonne (clic). Export CSV disponible.',
            'Insights automatiques' => 'Produit star 🏆, plus forte croissance 📈, alerte marge ⚠️, meilleur jour 🕐 — calculés automatiquement selon la période.',
        ],
    ],
    'users' => [
        'icon' => '👥',
        'title' => 'Utilisateurs',
        'color' => '#6150aa',
        'items' => [
            'Changer un rôle' => 'Sélectionne ADMIN, TRESORERIE ou ELEVE dans le menu déroulant. Chaque changement est journalisé (audit log) + un email de notification est envoyé.',
            'Activer / désactiver' => 'Bouton « Désactiver » → l\'utilisateur ne peut plus se connecter. Ses données sont conservées. « Activer » pour réactiver.',
            'Reset MDP (mot de passe)' => 'Bouton « Reset MDP » → génère un mot de passe temporaire aléatoire. Affiché dans le flash ET envoyé par email à l\'utilisateur. Il devra le changer rapidement.',
            'Supprimer un compte' => 'Bouton « Supprimer » → les données perso sont anonymisées (RGPD). Les données comptables sont conservées mais déliées de l\'identité. Action irréversible.',
            'Badge « Membre ✅ »' => 'Apparaît à côté des utilisateurs à jour de cotisation. Clique « Marquer payée » pour définir le paiement.',
            'Sécurité' => 'Impossible de supprimer son propre compte depuis l\'admin. Impossible de supprimer/rétrograder le dernier administrateur. Le 2FA est obligatoire pour ADMIN et TRESORERIE.',
        ],
    ],
    'memberships' => [
        'icon' => '💳',
        'title' => 'Adhésions / Cotisations',
        'color' => '#f59e0b',
        'items' => [
            'Saison scolaire' => 'Calculée automatiquement : si on est en juillet-décembre → saison = année/année+1 (ex: 2026-2027). Sinon → année-1/année.',
            'Marquer payée' => 'Admin → Adhésions → ou depuis Utilisateurs → « Marquer payée ». Saisis le montant. Le badge « Membre ✅ » apparaît.',
            'Filtre par saison' => 'Dans la page Adhésions, sélectionne la saison pour voir les cotisations d\'une année précise.',
            'Statuts' => 'Payée (vert) = cotisation réglée. En attente (ambre) = créée mais non payée. Expirée (gris) = ancienne saison non payée.',
            'Statistiques' => 'Le tableau de bord affiche « X à jour de cotisation (saison YYYY-YYYY) ».',
        ],
    ],
    'team' => [
        'icon' => '👥',
        'title' => 'Équipe (bureau)',
        'color' => '#48bdd3',
        'items' => [
            'Ajouter un membre' => 'Admin → Équipe → « + Nouveau membre ». Prénom, nom, rôle (Président, Trésorier...), pôle, bio courte, photo (URL), mis en avant, actif.',
            'Mise en avant' => 'Les membres « Mis en avant » apparaissent en premier sur la page /team (bureau restreint).',
            'Photo' => 'URL d\'une image uploadée dans Médias. Sans photo → placeholder (initiales).',
            'Pôles' => 'Permet de grouper les membres (bureau, communication, événements, cafétéria).',
        ],
    ],
    'pages' => [
        'icon' => '📄',
        'title' => 'Pages (CMS)',
        'color' => '#6150aa',
        'items' => [
            'Pages existantes' => 'Mentions légales (/legal), Politique de confidentialité (/privacy), CGU (/cgu), L\'association (/presentation).',
            'Modifier une page' => 'Admin → Pages → clique sur la page → modifie titre, contenu (HTML), méta SEO → Enregistrer.',
            'Contenu HTML' => 'Tu peux utiliser <h2>, <p>, <ul>, <li>, <strong>, <a href="..."> etc. Le contenu est rendu en « prose » (styles automatiques).',
            'Page non publiée' => 'Si dépubliée → la page affiche « Contenu à venir » sur le site.',
        ],
    ],
    'medias' => [
        'icon' => '🖼️',
        'title' => 'Bibliothèque de médias',
        'color' => '#22c55e',
        'items' => [
            'Uploader une image' => 'Admin → Médias → clique ou glisse-dépose une image dans la zone. Ajoute un texte alternatif (accessibilité). Clique « Téléverser ».',
            'Utiliser une image' => 'Clique « Copier l\'URL » → l\'URL est copiée → colle-la dans le champ Image d\'un événement, produit, ou membre d\'équipe.',
            'Formats acceptés' => 'JPG, PNG, GIF, WebP, SVG — 5 Mo maximum.',
            'Supprimer un média' => 'Bouton « Supprimer » → confirmation. L\'image est définitivement supprimée du serveur.',
            'Galerie publique' => 'Toutes les images uploadées apparaissent aussi sur la page /galerie (section « Autres photos »).',
        ],
    ],
    'settings' => [
        'icon' => '⚙️',
        'title' => 'Paramètres du site',
        'color' => '#48bdd3',
        'items' => [
            'Nom et description' => 'Modifie le nom affiché partout (navbar, footer, emails) et la description courte (accueil + SEO).',
            'Emails / SMTP (Brevo)' => 'Renseigne la clé API Brevo (recommandé). Test avec « Envoyer un e-mail de test ». L\'adresse d\'expédition doit être un domaine vérifié (ex: contact@aremond.ovh).',
            'SumUp (paiement)' => 'Colle l\'URL de paiement SumUp + active le toggle. Les boutons « Payer en ligne » apparaissent sur les événements.',
            'Discord (annonces auto)' => 'Colle l\'URL du webhook Discord + active. À chaque création d\'événement/sondage → un message embed est envoyé sur Discord.',
            'Contact & carte' => 'Adresse + latitude/longitude pour la carte « Où nous trouver ». Ajuste les coordonnées si le marqueur n\'est pas pile au bon endroit.',
            'Mode maintenance' => 'Active pour bloquer le site public (seul l\'admin garde l\'accès). Désactive quand tu as fini.',
            'Fonctionnalités' => 'Toggle pour activer/désactiver : inscriptions aux événements, commandes en ligne.',
        ],
    ],
    'emails' => [
        'icon' => '📧',
        'title' => 'Emails automatiques',
        'color' => '#f59e0b',
        'items' => [
            'Inscription d\'un nouvel élève' => 'Un mot de passe temporaire est généré et envoyé par email. L\'élève peut se connecter immédiatement.',
            'Rappel 24h avant événement' => 'Automatique (cron toutes les 15 min). Email « Plus que 24h ! » envoyé à tous les inscrits.',
            'Rappel 1h avant événement' => 'Automatique. Email « Ça commence dans 1h ! » envoyé à tous les inscrits.',
            'Reset MDP (admin)' => 'Le mot de passe temporaire est envoyé à l\'utilisateur concerné par email.',
            'Changement de mot de passe' => 'L\'utilisateur reçoit une confirmation « Votre mot de passe a été modifié ».',
            'Suppression de compte' => 'Email de confirmation RGPD envoyé avant anonymisation des données.',
            'Liste d\'attente promue' => 'Quand une place se libère → l\'utilisateur promu reçoit « Une place s\'est libérée ! Vous êtes inscrit ».',
            'Vérifier que les emails partent' => 'Admin → Paramètres → Emails → « Envoyer un e-mail de test ». Si échec → vérifier la clé API Brevo et l\'adresse d\'expédition.',
        ],
    ],
    'discord' => [
        'icon' => '🎮',
        'title' => 'Discord (annonces automatiques)',
        'color' => '#6150aa',
        'items' => [
            'Configuration' => 'Admin → Paramètres → « Réseaux sociaux & Discord » → colle l\'URL du webhook Discord → active.',
            'Quand un message est envoyé ?' => 'À la création d\'un nouvel événement publié (embed teal avec titre, date, lieu) et d\'un nouveau sondage publié (embed violet).',
            'Non bloquant' => 'Si Discord est down ou l\'URL est invalide → l\'événement est quand même créé. Le message Discord échoue silencieusement.',
            'Obtenir l\'URL du webhook' => 'Discord → Paramètres du serveur → Integrations → Webhooks → New Webhook → Copy Webhook URL.',
        ],
    ],
    'security' => [
        'icon' => '🔐',
        'title' => 'Sécurité & bonnes pratiques',
        'color' => '#ef4444',
        'items' => [
            '2FA obligatoire' => 'Tous les ADMIN et TRESORERIE doivent avoir le 2FA activé. Utilise Google Authenticator, Authy ou équivalent.',
            'Ne pas partager les mots de passe' => 'Chaque personne a SON compte. Les mots de passe ne doivent jamais être partagés. Pour donner l\'accès → crée un compte + attribue le rôle.',
            'Changer les mots de passe' => 'Régulièrement (Mon compte → Changer mon mot de passe). En cas de départ d\'un membre du bureau → désactive son compte.',
            'Dernier admin' => 'Le système empêche de supprimer ou rétrograder le dernier administrateur actif (anti-lockout).',
            'Audit log' => 'Toutes les actions sensibles (création/suppression, changement de rôle, reset MDP) sont journalisées et visibles dans le tableau de bord.',
            'Sauvegardes automatiques' => 'Une sauvegarde de la base de données tourne chaque nuit à 3h (cron). En cas de problème → contacter Remond Adrien.',
            'RGPD' => 'Chaque utilisateur peut exporter ses données (JSON) et supprimer son compte (anonymisation). Les consentements sont journalisés.',
        ],
    ],
    'tips' => [
        'icon' => '💡',
        'title' => 'Conseils pratiques',
        'color' => '#f59e0b',
        'items' => [
            'Avant un événement' => 'Crée l\'événement à l\'avance → vérifie la catégorie + carte activée + prix + lien SumUp. Teste l\'inscription toi-même.',
            'Après un événement' => 'Ajoute les photos dans Médias. Elles apparaîtront dans la galerie. Importe le rapport SumUp si vente au comptoir.',
            'Mensuellement' => 'Importe le rapport SumUp → vérifie les aliases non mappés → saisis les coûts de revient des nouveaux produits → vérifie le réappro.',
            'Début d\'année' => 'Crée les adhésions pour les nouveaux membres → marque « Payée » au fur et à mesure → mets à jour l\'équipe (nouveaux membres du bureau).',
            'Multilingue' => 'Le site supporte 9 langues (FR, EN, DE, ES, ZH, JA, PL, RU, MS). L\'interface se traduit automatiquement. Le contenu (événements, descriptions) reste en français.',
            'Mode sombre / clair' => 'Bouton ☀️/🌙 dans la navbar. La préférence est sauvegardée (localStorage).',
            'Installer le site comme app' => 'Sur Chrome mobile/desktop → « Installer » dans le menu. Le site fonctionne comme une app native (PWA).',
        ],
    ],
];
?>
<div class="wiki-page">

    <div class="wiki-hero">
        <div class="wiki-hero-inner">
            <span class="wiki-hero-icon">📚</span>
            <h1 class="wiki-hero-title">Guide de l'administrateur</h1>
            <p class="wiki-hero-sub">Tout ce qu'il faut savoir pour gérer le site AEIC. Destiné à tous les membres du bureau.</p>
        </div>
    </div>

    <div class="wiki-toolbar">
        <input type="text" id="wiki-search" class="wiki-search-input" placeholder="🔎 Rechercher dans le guide..." autocomplete="off">
        <span class="wiki-count muted" id="wiki-count"></span>
    </div>

    <div class="wiki-toc">
        <?php foreach ($sections as $key => $sec): ?>
            <a href="#wiki-<?= e($key) ?>" class="wiki-toc-pill" style="--pill-color: <?= e($sec['color']) ?>">
                <span class="wiki-toc-emoji"><?= e($sec['icon']) ?></span>
                <span><?= e($sec['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="wiki-grid">
        <?php foreach ($sections as $key => $sec): ?>
            <section class="wiki-card" id="wiki-<?= e($key) ?>" data-search="<?= e(strtolower($sec['title'] . ' ' . implode(' ', array_keys($sec['items'])) . ' ' . implode(' ', array_values($sec['items'])))) ?>" style="--card-accent: <?= e($sec['color']) ?>">
                <div class="wiki-card-head">
                    <span class="wiki-card-emoji"><?= e($sec['icon']) ?></span>
                    <h2 class="wiki-card-title"><?= e($sec['title']) ?></h2>
                </div>
                <div class="wiki-card-body">
                    <?php foreach ($sec['items'] as $q => $a): ?>
                        <div class="wiki-faq" data-search="<?= e(strtolower($q . ' ' . $a)) ?>">
                            <h3 class="wiki-faq-q"><?= e($q) ?></h3>
                            <p class="wiki-faq-a"><?= e($a) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="wiki-footer">
        <p>💻 Développé par <strong style="color:var(--primary)">Remond Adrien</strong> · © 2026 AEIC · 100 % étudiant</p>
    </div>
</div>

<style>
.wiki-page { display: flex; flex-direction: column; gap: 1.5rem; }

.wiki-hero {
    background: linear-gradient(135deg, rgba(72,189,211,0.08), rgba(97,80,170,0.08));
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 2.5rem 2rem;
    text-align: center;
}
.wiki-hero-icon { font-size: 3rem; display: block; margin-bottom: 0.5rem; }
.wiki-hero-title { font-size: 1.8rem; font-weight: 900; margin: 0 0 0.5rem; color: var(--primary); text-transform: none; letter-spacing: -0.02em; }
.wiki-hero-sub { color: var(--muted); font-size: 0.95rem; margin: 0; max-width: 500px; margin: 0 auto; }

.wiki-toolbar {
    display: flex; align-items: center; gap: 1rem;
    position: sticky; top: 0; z-index: 20;
    background: var(--admin-bg, #0a1b33);
    padding: 0.75rem 0;
}
.wiki-search-input {
    flex: 1;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--foreground);
    padding: 0.6rem 1rem;
    font-size: 0.95rem;
}
.wiki-search-input:focus { outline: none; border-color: var(--primary); }
.wiki-count { font-size: 0.8rem; white-space: nowrap; }

.wiki-toc {
    display: flex; flex-wrap: wrap; gap: 0.4rem;
}
.wiki-toc-pill {
    display: inline-flex; align-items: center; gap: 0.35rem;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--pill-color, var(--border));
    border-radius: 999px;
    padding: 0.35rem 0.8rem;
    font-size: 0.78rem; font-weight: 600;
    color: var(--muted);
    text-decoration: none;
    transition: all 0.15s;
}
.wiki-toc-pill:hover {
    color: var(--foreground);
    background: color-mix(in srgb, var(--pill-color) 10%, transparent);
    transform: translateY(-1px);
}
.wiki-toc-emoji { font-size: 0.9rem; }

.wiki-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1.25rem;
}
@media (max-width: 860px) { .wiki-grid { grid-template-columns: 1fr; } }

.wiki-card {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border);
    border-top: 3px solid var(--card-accent, var(--primary));
    border-radius: 14px;
    overflow: hidden;
    transition: border-color 0.2s;
}
.wiki-card:hover { border-color: color-mix(in srgb, var(--card-accent) 30%, var(--border)); }

.wiki-card-head {
    display: flex; align-items: center; gap: 0.65rem;
    padding: 1.1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    background: color-mix(in srgb, var(--card-accent) 5%, transparent);
}
.wiki-card-emoji { font-size: 1.4rem; }
.wiki-card-title {
    font-size: 1.05rem; font-weight: 800; margin: 0;
    color: var(--card-accent, var(--primary));
    text-transform: none; letter-spacing: -0.01em;
}

.wiki-card-body { padding: 1rem 1.25rem 1.25rem; }

.wiki-faq {
    padding: 0.75rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.wiki-faq:last-child { border-bottom: none; }
.wiki-faq-q {
    font-size: 0.88rem; font-weight: 700;
    margin: 0 0 0.3rem;
    color: var(--foreground);
}
.wiki-faq-a {
    font-size: 0.82rem; color: var(--muted);
    margin: 0; line-height: 1.6;
}

.wiki-footer {
    text-align: center;
    padding: 1.5rem 0 0.5rem;
    border-top: 1px solid var(--border);
}
.wiki-footer p { font-size: 0.82rem; color: var(--muted); margin: 0; }

.wiki-faq.is-hidden, .wiki-card.is-hidden { display: none; }
</style>

<script>
(function () {
    var search = document.getElementById('wiki-search');
    var countEl = document.getElementById('wiki-count');
    if (!search) return;
    function norm(s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

    search.addEventListener('input', function () {
        var q = norm(search.value.trim());
        var cards = document.querySelectorAll('.wiki-card');
        var visibleFaqs = 0;

        cards.forEach(function (card) {
            var faqs = card.querySelectorAll('.wiki-faq');
            var anyVisible = false;
            faqs.forEach(function (faq) {
                var match = q === '' || norm(faq.getAttribute('data-search') || '').indexOf(q) !== -1;
                faq.classList.toggle('is-hidden', !match);
                if (match) { anyVisible = true; visibleFaqs++; }
            });
            card.classList.toggle('is-hidden', !anyVisible);
        });

        if (q === '') {
            countEl.textContent = '';
        } else {
            countEl.textContent = visibleFaqs + ' résultat' + (visibleFaqs > 1 ? 's' : '');
        }
    });
})();
</script>
