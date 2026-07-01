<?php

declare(strict_types=1);

/** @var array<string,mixed> $user */
?>
<div class="wiki">

<!-- ===================== HERO ===================== -->
<header class="wiki-hero">
    <span class="wiki-hero-emoji">📚</span>
    <h1>Guide de l'administrateur</h1>
    <p>Tout ce qu'il faut savoir pour gérer le site AEIC au quotidien.</p>
</header>

<!-- ===================== RECHERCHE ===================== -->
<div class="wiki-search-wrap">
    <input type="text" id="wiki-search" placeholder="🔎 Rechercher..." autocomplete="off">
    <span id="wiki-count" class="wiki-count"></span>
</div>

<div class="wiki-body" id="wiki-body">

<!-- ===================== 1. DÉMARRAGE ===================== -->
<section class="wiki-section" id="sec-start">
    <h2>🚀 Démarrage & connexion</h2>

    <div class="wiki-block">
        <h3>Comment se connecter</h3>
        <p>Tu as reçu un email avec ton mot de passe temporaire. Voici les étapes :</p>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Va sur le site</strong><br><code>https://asso.aremond.ovh/login</code></div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>Entre ton email</strong> (celui avec lequel tu t'es inscrit) et ton mot de passe.</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Configure le 2FA</strong> si c'est ta première connexion (voir ci-dessous).</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div><strong>Clique sur « Admin »</strong> en haut à droite pour accéder à l'espace d'administration.</div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Le 2FA (authentification à deux facteurs)</h3>
        <p>Le 2FA est <strong>obligatoire</strong> pour les administrateurs et trésoriers. Il ajoute une couche de sécurité : même si quelqu'un vole ton mot de passe, il ne peut pas se connecter sans ton téléphone.</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-box">📧 Email + mot de passe</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">📱 Code à 6 chiffres (app Authenticator)</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box wiki-diagram-ok">✅ Connecté</div>
        </div>
        <p><strong>Comment configurer :</strong> à la première connexion, un QR code s'affiche. Scanne-le avec <strong>Google Authenticator</strong> (Android/iOS). L'app génère un code à 6 chiffres qui change toutes les 30 secondes.</p>
    </div>

    <div class="wiki-block">
        <h3>Mot de passe oublié</h3>
        <p>Sur la page de connexion → <strong>« Mot de passe oublié ? »</strong> → entre ton email → un lien de réinitialisation t'est envoyé. Clique le lien → choisis un nouveau mot de passe (8 caractères min, 1 lettre + 1 chiffre).</p>
        <p>Un admin peut aussi faire un <strong>« Reset MDP »</strong> depuis Admin → Utilisateurs → ça génère un mot de passe temporaire envoyé par email.</p>
    </div>

    <div class="wiki-block">
        <h3>Les 3 rôles</h3>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head">
                <span>Rôle</span><span>Accès</span><span>Qui ?</span>
            </div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-teal">ADMIN</span><span>Tout l'espace d'administration</span><span>Président, membres du bureau de confiance</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-violet">TRESORERIE</span><span>Uniquement la comptabilité (import, coûts, réappro, analytics)</span><span>Trésorier</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-muted">ELEVE</span><span>Espace membre uniquement (pas d'admin)</span><span>Tous les étudiants inscrits</span></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Changer son mot de passe</h3>
        <p>Une fois connecté → clique sur ton prénom en haut à droite → <strong>« Mes données »</strong> → <strong>« Changer mon mot de passe »</strong> → saisie l'ancien + le nouveau + confirmation → <strong>Modifier</strong>. Un email de confirmation est envoyé automatiquement.</p>
    </div>
</section>

<!-- ===================== 2. ÉVÉNEMENTS ===================== -->
<section class="wiki-section" id="sec-events">
    <h2>📅 Créer un événement</h2>

    <div class="wiki-block">
        <h3>Étapes pour créer un événement</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Admin → Événements</strong> → clique <strong>« + Nouvel événement »</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>Titre</strong> : le nom de l'événement (ex: « Soirée d'intégration »)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Slug</strong> : l'URL (ex: <code>soiree-integration</code>). Auto-généré si vide.</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div><strong>Catégorie</strong> : Soirée, Tournoi/LAN, Conférence, Barbecue, Sortie...</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div><strong>Extrait</strong> : résumé court affiché sur les cartes (max 1 phrase)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">6</span><div><strong>Description</strong> : texte complet en HTML (<code>&lt;p&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;strong&gt;</code>...)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">7</span><div><strong>Date et heure</strong> + <strong>Lieu</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">8</span><div><strong>Options</strong> : prix, capacité max, carte, SumUp, mis en avant</div></div>
            <div class="wiki-step"><span class="wiki-step-n">9</span><div>Coche <strong>« Publié »</strong> → <strong>Enregistrer</strong></div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Capacité max et liste d'attente</h3>
        <p>Si tu mets une <strong>capacité max</strong> (ex: 50 places), voici comment ça marche :</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-box">📝 Élève s'inscrit</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">Places restantes ?</div>
            <div class="wiki-arrow">↓ ↓</div>
            <div class="wiki-diagram-row">
                <div class="wiki-diagram-box wiki-diagram-ok">✅ Oui → Inscription confirmée + QR code</div>
                <div class="wiki-diagram-box wiki-diagram-warn">⚠️ Non → Liste d'attente (position X)</div>
            </div>
            <div class="wiki-arrow">↓ (si quelqu'un se désinscrit)</div>
            <div class="wiki-diagram-box wiki-diagram-ok">✅ Premier de la file promu + email automatique</div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Gérer les inscriptions</h3>
        <p>Dans la liste des événements, clique l'icône 📋 → tu vois :</p>
        <ul class="wiki-list">
            <li>Liste des inscrits (nom, prénom, date d'inscription)</li>
            <li>Leurs choix (menus, options) si l'événement a des variantes</li>
            <li>Le statut de présence (✅/⬜) si le check-in QR a été fait</li>
            <li>La <strong>liste d'attente</strong> en bas (si l'événement est complet)</li>
        </ul>
        <p>Bouton <strong>« Export CSV »</strong> pour télécharger la liste.</p>
    </div>
</section>

<!-- ===================== 3. CHECK-IN QR ===================== -->
<section class="wiki-section" id="sec-checkin">
    <h2>📱 Check-in QR (le jour J)</h2>

    <div class="wiki-block">
        <h3>Comment scanner les participants</h3>
        <p>Quand un élève s'inscrit à un événement, un <strong>QR code unique</strong> est généré. Il est visible sur la page de l'événement (si l'élève est connecté et inscrit).</p>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Le jour J</strong> → Admin → Événements → 📋 → <strong>« Ouvrir le check-in »</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Une page avec un <strong>champ de saisie</strong> s'ouvre (autofocus)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Scanne le QR</strong> du participant avec la caméra du téléphone (ou saisis le token manuellement) → Entrée</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Résultat : <span class="wiki-tag wiki-tag-green">✅ Présent</span> ou <span class="wiki-tag wiki-tag-warn">⚠️ Déjà checké</span></div></div>
        </div>
    </div>
</section>

<!-- ===================== 4. SONDAGES ===================== -->
<section class="wiki-section" id="sec-sondages">
    <h2>📊 Créer un sondage</h2>

    <div class="wiki-block">
        <h3>Étapes</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Admin → Sondages</strong> → « + Nouveau sondage »</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>Titre</strong> (ex: « Chocolatine ou pain au chocolat ? ») + <strong>description</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>Clique <strong>« + Ajouter une option »</strong> pour chaque choix</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div><strong>Choix unique</strong> (radio) ou <strong>choix multiple</strong> (checkbox)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div>Coche <strong>« Publié »</strong> → Enregistrer</div></div>
        </div>
        <p>Les élèves votent sur <code>/sondages</code>. Une seule fois. Après le vote → <strong>résultats en direct</strong> (barres + %).</p>
    </div>
</section>

<!-- ===================== 5. CAFÉTÉRIA ===================== -->
<section class="wiki-section" id="sec-cafeteria">
    <h2>☕ Cafétéria — Produits & carte</h2>

    <div class="wiki-block">
        <h3>Ajouter un produit au menu</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Admin → Cafétéria → Produits</strong> → « + Nouveau produit »</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>Nom</strong> (ex: « Red Bull ») + <strong>Description</strong> (ex: « Boisson énergisante »)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Prix de vente</strong> (ex: <code>1,50</code>) + <strong>Catégorie</strong> (Boissons, Snacks...)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div><strong>Image</strong> (optionnel) : colle une URL d'une image uploadée dans Médias</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div><strong>Stock</strong> (pour le réappro) + <strong>Disponible</strong> ✅ + <strong>Actif</strong> ✅</div></div>
            <div class="wiki-step"><span class="wiki-step-n">6</span><div>Enregistrer → le produit apparaît dans <strong>« Notre carte »</strong> sur l'accueil</div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Emojis automatiques</h3>
        <p>Si tu ne mets pas d'image, le site attribue automatiquement un emoji selon le nom :</p>
        <div class="wiki-emoji-grid">
            <span>🥤 Coca, Fanta, Oasis, Orangina</span>
            <span>💧 Eau, Cristaline, Perrier</span>
            <span>⚡ Monster, Red Bull</span>
            <span>🍫 Bueno, KitKat, Mars, Snickers</span>
            <span>🍟 Chips</span>
            <span>🍬 Bonbon</span>
            <span>🍵 Lipton</span>
            <span>🧃 Minute Maid, Pulco</span>
        </div>
    </div>
</section>

<!-- ===================== 6. COMPTABILITÉ ===================== -->
<section class="wiki-section" id="sec-compta">
    <h2>💰 Comptabilité — Importer SumUp</h2>

    <div class="wiki-block">
        <h3>Récupérer le rapport SumUp</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>Ouvre l'<strong>app SumUp</strong> sur ton téléphone</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Va dans <strong>Reports</strong> → sélectionne la période (ex: le mois écoulé)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Export CSV</strong> → le fichier est téléchargé</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Sur le site : <strong>Admin → Comptabilité → Importer CSV</strong> → choisis le fichier → <strong>Importer</strong></div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Que fait le système à l'import ?</h3>
        <div class="wiki-diagram">
            <div class="wiki-diagram-box">📄 Fichier CSV SumUp</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">🔍 Parse : dates FR, prix (virgule), moyen de paiement</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">💳 Normalise : Visa/Mastercard → CARTE, Espèces → LIQUIDE</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">🚫 Déduplication : clé unique (ref + date + produit). Réimport = 0 doublon.</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box wiki-diagram-ok">✅ Ventes disponibles dans tout le module compta</div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Mapping des libellés (important !)</h3>
        <p>SumUp enregistre parfois le <strong>même produit sous des noms différents</strong> :</p>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head"><span>Libellés bruts SumUp</span><span>→ Produit canonique</span></div>
            <div class="wiki-table-row"><span>Bueno / Bueno_white</span><span>Bueno</span></div>
            <div class="wiki-table-row"><span>CocaCola / Coca cherry / Coca_cherry / Coca Cola</span><span>Coca</span></div>
            <div class="wiki-table-row"><span>Monster Blanche / Monster_Bleue / Monster rose</span><span>Monster</span></div>
        </div>
        <p><strong>Solution :</strong> Admin → Comptabilité → Mapping libellés → <strong>« Auto-détecter les doublons »</strong> → vérifier → <strong>Appliquer</strong>. Toutes les ventes sont fusionnées sous un seul nom.</p>
    </div>

    <div class="wiki-block">
        <h3>Coûts de revient (pour calculer le vrai bénéfice)</h3>
        <p>Le bénéfice = <strong>prix de vente − coût d'achat</strong>. Sans coût saisi → marge à 100% (faux).</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-box">💵 Prix de vente TTC : 1,00 €</div>
            <div class="wiki-arrow">−</div>
            <div class="wiki-diagram-box">🛒 Coût d'achat : 0,60 €</div>
            <div class="wiki-arrow">=</div>
            <div class="wiki-diagram-box wiki-diagram-ok">💰 Bénéfice : 0,40 € (marge 40%)</div>
        </div>
        <p><strong>Comment :</strong> Admin → Comptabilité → Coûts de revient → recherche le produit → saisis le coût → Enregistrer. Si le prix d'achat change → crée un nouveau lot daté.</p>
    </div>
</section>

<!-- ===================== 7. RÉAPPRO ===================== -->
<section class="wiki-section" id="sec-reappro">
    <h2>📦 Réapprovisionnement</h2>

    <div class="wiki-block">
        <h3>Comment savoir combien racheter</h3>
        <p>La page calcule automatiquement les quantités à racheter, basé sur :</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-row">
                <div class="wiki-diagram-box">📊 Ventes réelles<br>(moyenne 3 mois)</div>
                <div class="wiki-diagram-box">📅 Jours d'ouverture<br>(lun-ven = 22j/mois)</div>
                <div class="wiki-diagram-box">📦 Stock actuel<br>(saisi par toi)</div>
            </div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box wiki-diagram-ok">✅ « À commander : X unités »</div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Utilisation</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>Choisis la <strong>période</strong> à couvrir (1 semaine, 1 mois, 3 mois...)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Saisis le <strong>stock actuel</strong> de chaque produit dans le champ</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>La colonne <strong>« À commander »</strong> se recalcule automatiquement</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Clique <strong>« Enregistrer les stocks »</strong> pour sauvegarder</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div>Le <strong>total en bas</strong> te donne la quantité globale à commander</div></div>
        </div>
    </div>
</section>

<!-- ===================== 8. ANALYTICS ===================== -->
<section class="wiki-section" id="sec-analytics">
    <h2>📈 Dashboard Analytics</h2>

    <div class="wiki-block">
        <h3>Filtres globaux</h3>
        <p>En haut de la page, choisis :</p>
        <ul class="wiki-list">
            <li><strong>Période</strong> : 7j / 30j / 3 mois / 6 mois / 12 mois / dates personnalisées</li>
            <li><strong>Granularité</strong> : Jour / Semaine / Mois (affecte les graphiques de tendance)</li>
            <li><strong>Catégorie</strong> : filtrer par Boisson, Nourriture, Spécial...</li>
            <li><strong>Paiement</strong> : filtrer par Carte ou Liquide</li>
        </ul>
        <p>Les filtres sont <strong>partageables par URL</strong> (envoie le lien à quelqu'un).</p>
    </div>

    <div class="wiki-block">
        <h3>Les 6 indicateurs (KPI)</h3>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head"><span>Indicateur</span><span>Signification</span></div>
            <div class="wiki-table-row"><span><strong>CA TTC</strong></span><span>Chiffre d'affaires total + variation vs période précédente</span></div>
            <div class="wiki-table-row"><span><strong>Bénéfice net</strong></span><span>CA − coûts d'achat + marge en %</span></div>
            <div class="wiki-table-row"><span><strong>Volume vendu</strong></span><span>Nombre total d'unités vendues</span></div>
            <div class="wiki-table-row"><span><strong>Panier moyen</strong></span><span>CA divisé par le nombre de transactions</span></div>
            <div class="wiki-table-row"><span><strong>Transactions</strong></span><span>Nombre de lignes de vente</span></div>
            <div class="wiki-table-row"><span><strong>Nouveaux membres</strong></span><span>Étudiants inscrits sur la période</span></div>
        </div>
    </div>
</section>

<!-- ===================== 9. UTILISATEURS ===================== -->
<section class="wiki-section" id="sec-users">
    <h2>👥 Utilisateurs & adhésions</h2>

    <div class="wiki-block">
        <h3>Actions possibles sur un utilisateur</h3>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head"><span>Action</span><span>Effet</span></div>
            <div class="wiki-table-row"><span>🔄 <strong>Changer le rôle</strong></span><span>ELEVE → ADMIN ou TRESORERIE. Journalisé + email envoyé.</span></div>
            <div class="wiki-table-row"><span>🔒 <strong>Désactiver</strong></span><span>Bloque la connexion. Données conservées.</span></div>
            <div class="wiki-table-row"><span>🔑 <strong>Reset MDP</strong></span><span>Génère un mot de passe temporaire envoyé par email.</span></div>
            <div class="wiki-table-row"><span>🗑️ <strong>Supprimer</strong></span><span>Anonymise les données (RGPD). Comptabilité conservée anonyme.</span></div>
            <div class="wiki-table-row"><span>💳 <strong>Marquer payée</strong></span><span>Définit la cotisation comme payée pour la saison.</span></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Sécurité</h3>
        <div class="wiki-alert wiki-alert-warn">
            <strong>⚠️ Règles de sécurité :</strong>
            <ul class="wiki-list">
                <li>Tu ne peux <strong>pas</strong> supprimer ton propre compte depuis l'admin</li>
                <li>Tu ne peux <strong>pas</strong> supprimer/rétrograder le dernier administrateur</li>
                <li>Le <strong>2FA est obligatoire</strong> pour ADMIN et TRESORERIE</li>
                <li>Chaque action est <strong>journalisée</strong> (audit log visible dans le tableau de bord)</li>
            </ul>
        </div>
    </div>
</section>

<!-- ===================== 10. EMAILS ===================== -->
<section class="wiki-section" id="sec-emails">
    <h2>📧 Emails automatiques</h2>

    <div class="wiki-block">
        <p>Le site envoie automatiquement ces emails (si le SMTP/API Brevo est configuré) :</p>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head"><span>Déclencheur</span><span>Email envoyé</span></div>
            <div class="wiki-table-row"><span>📝 Inscription d'un élève</span><span>Mot de passe temporaire (« Bienvenue à l'AEIC »)</span></div>
            <div class="wiki-table-row"><span>📅 24h avant événement</span><span>« Plus que 24h ! » avec détails (date, lieu)</span></div>
            <div class="wiki-table-row"><span>⏰ 1h avant événement</span><span>« Ça commence dans 1h ! »</span></div>
            <div class="wiki-table-row"><span>🔑 Reset MDP (admin)</span><span>Mot de passe temporaire à l'utilisateur</span></div>
            <div class="wiki-table-row"><span>🔐 Changement de mot de passe</span><span>« Votre mot de passe a été modifié »</span></div>
            <div class="wiki-table-row"><span>🗑️ Suppression de compte</span><span>Confirmation RGPD (anonymisation)</span></div>
            <div class="wiki-table-row"><span>✅ Liste d'attente promue</span><span>« Une place s'est libérée ! Vous êtes inscrit »</span></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Vérifier que les emails partent</h3>
        <p>Admin → Paramètres → Emails/SMTP → <strong>« Envoyer un e-mail de test »</strong> → tape ton adresse → si tu reçois l'email → ✅ tout marche.</p>
        <p>Si échec → vérifie la <strong>clé API Brevo</strong> et l'<strong>adresse d'expédition</strong> (doit être un domaine vérifié comme <code>contact@aremond.ovh</code>).</p>
    </div>
</section>

<!-- ===================== 11. PARAMÈTRES ===================== -->
<section class="wiki-section" id="sec-settings">
    <h2>⚙️ Paramètres du site</h2>

    <div class="wiki-block">
        <h3>Configuration Brevo (emails)</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>Crée un compte sur <strong>brevo.com</strong> (gratuit, 300 emails/jour)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Vérifie ton expéditeur (ex: <code>contact@aremond.ovh</code>)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>Génère une <strong>clé API</strong> (<code>xkeysib-...</code>) dans Brevo → SMTP & API</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Sur le site : Admin → Paramètres → « Clé API Brevo » → colle la clé</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div>« Adresse d'expédition » → <code>contact@aremond.ovh</code></div></div>
            <div class="wiki-step"><span class="wiki-step-n">6</span><div>Test avec « Envoyer un e-mail de test »</div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Discord (annonces auto)</h3>
        <p>Pour que chaque nouvel événement/sondage soit annoncé automatiquement sur Discord :</p>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>Discord → Paramètres du serveur → <strong>Integrations → Webhooks</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>New Webhook</strong> → choisis le salon → <strong>Copy URL</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>Admin → Paramètres → « URL Webhook Discord » → colle l'URL</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Active le toggle → Enregistrer</div></div>
        </div>
    </div>
</section>

<!-- ===================== 12. CONSEILS ===================== -->
<section class="wiki-section" id="sec-tips">
    <h2>💡 Conseils pratiques</h2>

    <div class="wiki-block">
        <h3>Routine mensuelle (trésorier)</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>📥 <strong>Importe</strong> le rapport SumUp du mois écoulé</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>🔗 <strong>Mappe</strong> les nouveaux libellés non reconnus (aliases)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>💸 <strong>Saisis</strong> les coûts de revient des nouveaux produits</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>📦 <strong>Vérifie</strong> le réappro (quantités à racheter)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div>📈 <strong>Analyse</strong> le dashboard Analytics (tendances, insights)</div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Routine début d'année (président)</h3>
        <ul class="wiki-list">
            <li>👥 Crée les comptes pour les nouveaux membres du bureau</li>
            <li>🔑 Donne le rôle ADMIN aux nouveaux (2FA obligatoire)</li>
            <li>💳 Crée les adhésions pour la nouvelle saison</li>
            <li>👥 Mets à jour la page Équipe (nouveaux membres, photos)</li>
            <li>📅 Crée les événements de rentrée</li>
        </ul>
    </div>

    <div class="wiki-block">
        <h3>Si quelque chose ne marche pas</h3>
        <div class="wiki-alert wiki-alert-info">
            <strong>🔧 Dépannage rapide :</strong>
            <ul class="wiki-list">
                <li><strong>Page blanche / erreur 500</strong> → contacte Remond Adrien (développeur)</li>
                <li><strong>Emails ne partent pas</strong> → vérifier Paramètres → Brevo API key + adresse d'expédition</li>
                <li><strong>Chiffres à 0</strong> → importer un rapport SumUp (Comptabilité → Importer CSV)</li>
                <li><strong>Marges à 100%</strong> → saisir les coûts de revient (Comptabilité → Coûts)</li>
                <li><strong>QR code ne marche pas</strong> → l'élève doit se désinscrire puis se réinscrire</li>
            </ul>
        </div>
    </div>
</section>

</div><!-- /wiki-body -->

<div class="wiki-footer">
    <p>💻 Développé par <strong style="color:var(--primary)">Remond Adrien</strong> · © 2026 AEIC · 100 % étudiant</p>
</div>

</div><!-- /wiki -->

<style>
.wiki { display: flex; flex-direction: column; gap: 1.5rem; }

/* Hero */
.wiki-hero {
    text-align: center; padding: 2.5rem 1.5rem;
    background: linear-gradient(135deg, rgba(72,189,211,0.06), rgba(97,80,170,0.06));
    border: 1px solid var(--border); border-radius: 16px;
}
.wiki-hero-emoji { font-size: 3rem; display: block; }
.wiki-hero h1 { font-size: 1.8rem; font-weight: 900; margin: 0.5rem 0 0.25rem; color: var(--primary); text-transform: none; }
.wiki-hero p { color: var(--muted); font-size: 0.95rem; margin: 0; }

/* Recherche */
.wiki-search-wrap {
    position: sticky; top: 0; z-index: 20;
    display: flex; align-items: center; gap: 1rem;
    background: var(--admin-bg, #0a1b33); padding: 0.5rem 0;
}
.wiki-search-wrap input {
    flex: 1; background: rgba(255,255,255,0.05); border: 1px solid var(--border);
    border-radius: 10px; color: var(--foreground); padding: 0.65rem 1rem; font-size: 0.95rem;
}
.wiki-search-wrap input:focus { outline: none; border-color: var(--primary); }
.wiki-count { font-size: 0.8rem; color: var(--muted); white-space: nowrap; }

/* Sections (pleine largeur, 1 par ligne) */
.wiki-body { display: flex; flex-direction: column; gap: 1.5rem; }

.wiki-section {
    background: rgba(255,255,255,0.02); border: 1px solid var(--border);
    border-radius: 14px; padding: 0; overflow: hidden;
}
.wiki-section h2 {
    display: flex; align-items: center; gap: 0.6rem;
    font-size: 1.3rem; font-weight: 800; margin: 0;
    padding: 1.25rem 1.5rem;
    background: rgba(72,189,211,0.05); border-bottom: 1px solid var(--border);
    color: var(--foreground); text-transform: none; letter-spacing: -0.01em;
}
.wiki-num {
    display: inline-grid; place-items: center;
    width: 28px; height: 28px; border-radius: 8px;
    background: var(--primary); color: #08172d;
    font-size: 0.85rem; font-weight: 900; flex-shrink: 0;
}

.wiki-block { padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.03); }
.wiki-block:last-child { border-bottom: none; }
.wiki-block h3 { font-size: 1rem; font-weight: 700; margin: 0 0 0.6rem; color: var(--primary); text-transform: none; }
.wiki-block p { font-size: 0.9rem; color: var(--muted); line-height: 1.65; margin: 0 0 0.6rem; }
.wiki-block p:last-child { margin-bottom: 0; }
.wiki-block code {
    background: rgba(72,189,211,0.1); color: var(--primary);
    padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.82rem;
}

/* Listes */
.wiki-list { list-style: none; padding: 0; margin: 0.5rem 0; }
.wiki-list li { font-size: 0.88rem; color: var(--muted); padding: 0.3rem 0 0.3rem 1.2rem; position: relative; }
.wiki-list li::before { content: '▸'; position: absolute; left: 0; color: var(--primary); }

/* Steps (numérotés) */
.wiki-steps { display: flex; flex-direction: column; gap: 0.6rem; margin: 0.75rem 0; }
.wiki-step {
    display: flex; align-items: flex-start; gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: rgba(255,255,255,0.02); border-radius: 10px;
    border-left: 3px solid var(--primary);
}
.wiki-step-n {
    display: inline-grid; place-items: center;
    width: 24px; height: 24px; border-radius: 50%;
    background: var(--primary); color: #08172d;
    font-size: 0.78rem; font-weight: 800; flex-shrink: 0;
}
.wiki-step div { font-size: 0.88rem; color: var(--foreground); line-height: 1.5; }

/* Diagrammes */
.wiki-diagram {
    display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
    padding: 1.25rem; margin: 0.75rem 0;
    background: rgba(0,0,0,0.1); border-radius: 12px;
}
.wiki-diagram-box {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border);
    border-radius: 10px; padding: 0.6rem 1.2rem;
    font-size: 0.85rem; color: var(--foreground); text-align: center;
    min-width: 200px; max-width: 400px;
}
.wiki-diagram-box code { font-size: 0.78rem; }
.wiki-diagram-ok { border-color: rgba(34,197,94,0.3); background: rgba(34,197,94,0.08); }
.wiki-diagram-warn { border-color: rgba(245,158,11,0.3); background: rgba(245,158,11,0.08); }
.wiki-arrow { color: var(--muted); font-size: 1rem; }
.wiki-diagram-row { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; }

/* Tableaux */
.wiki-table { display: flex; flex-direction: column; margin: 0.75rem 0; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.wiki-table-row {
    display: grid; grid-template-columns: 1fr 2fr;
    gap: 0.5rem; padding: 0.6rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    font-size: 0.85rem; color: var(--muted);
}
.wiki-table-row:last-child { border-bottom: none; }
.wiki-table-head { background: rgba(255,255,255,0.03); font-weight: 700; color: var(--foreground); }
@media (max-width: 600px) { .wiki-table-row { grid-template-columns: 1fr; } }

/* Tags */
.wiki-tag {
    display: inline-block; padding: 0.15rem 0.5rem; border-radius: 6px;
    font-size: 0.75rem; font-weight: 700;
}
.wiki-tag-teal { background: rgba(72,189,211,0.15); color: var(--primary); }
.wiki-tag-violet { background: rgba(97,80,170,0.15); color: var(--secondary); }
.wiki-tag-muted { background: rgba(255,255,255,0.05); color: var(--muted); }
.wiki-tag-green { background: rgba(34,197,94,0.12); color: #4ade80; }
.wiki-tag-warn { background: rgba(245,158,11,0.12); color: #fbbf24; }

/* Alertes */
.wiki-alert {
    padding: 1rem 1.25rem; border-radius: 10px;
    border-left: 4px solid;
    font-size: 0.88rem; color: var(--muted); line-height: 1.6;
}
.wiki-alert-warn { border-color: #f59e0b; background: rgba(245,158,11,0.06); }
.wiki-alert-info { border-color: var(--primary); background: rgba(72,189,211,0.06); }
.wiki-alert strong { color: var(--foreground); }

/* Emoji grid */
.wiki-emoji-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0.75rem 0; }
.wiki-emoji-grid span {
    background: rgba(255,255,255,0.03); border: 1px solid var(--border);
    border-radius: 8px; padding: 0.4rem 0.75rem; font-size: 0.82rem; color: var(--muted);
}

/* Footer */
.wiki-footer { text-align: center; padding: 1.5rem 0; border-top: 1px solid var(--border); }
.wiki-footer p { font-size: 0.82rem; color: var(--muted); margin: 0; }

/* Recherche */
.wiki-block.is-hidden { display: none; }
.wiki-section.is-hidden { display: none; }
</style>

<script>
(function () {
    var search = document.getElementById('wiki-search');
    var countEl = document.getElementById('wiki-count');
    if (!search) return;
    function norm(s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

    search.addEventListener('input', function () {
        var q = norm(search.value.trim());
        var sections = document.querySelectorAll('.wiki-section');
        var visible = 0;

        sections.forEach(function (sec) {
            var blocks = sec.querySelectorAll('.wiki-block');
            var anyVisible = false;
            blocks.forEach(function (block) {
                var text = norm(block.textContent || '');
                var match = q === '' || text.indexOf(q) !== -1;
                block.classList.toggle('is-hidden', !match);
                if (match) { anyVisible = true; visible++; }
            });
            sec.classList.toggle('is-hidden', !anyVisible);
        });

        countEl.textContent = q === '' ? '' : visible + ' résultat' + (visible > 1 ? 's' : '');
    });
})();
</script>
