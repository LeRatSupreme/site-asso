<?php

declare(strict_types=1);

/** @var array<string,mixed> $user */
?>

<?php
$wikiRole = (string) ($user['role'] ?? '');
$wikiSystem = \App\Core\Permissions::isSystemAdmin();

/** Chaque section n'est visible que pour les rôles qui gèrent le module
 *  correspondant ; les sections Système suivent isSystemAdmin() ou une
 *  attribution individuelle (« Pages + » de la page Utilisateurs). */
$wikiPage = static fn (string $key): bool => $wikiSystem || \App\Core\Permissions::userHasExtraPage($key);

$wikiAccess = [
    'sec-start'      => true,
    'sec-events'     => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_EVENTS),
    'sec-checkin'    => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_EVENTS),
    'sec-sondages'   => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_CONTENT),
    'sec-cafeteria'  => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_CAFETERIA),
    'sec-jeux'       => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_GAMES),
    'sec-compta'     => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_COMPTA),
    'sec-couts'      => $wikiPage('costs'),
    'sec-caisse'     => \App\Core\Permissions::isAdminRole($wikiRole),
    'sec-reappro'    => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_COMPTA),
    'sec-analytics'  => \App\Core\Permissions::allows($wikiRole, \App\Core\Permissions::MODULE_COMPTA),
    'sec-caisses'    => $wikiPage('cash'),
    'sec-inventaire' => $wikiPage('inventory'),
    'sec-users'      => $wikiSystem,
    'sec-emails'     => true,
    'sec-settings'   => $wikiSystem,
    'sec-tips'       => true,
];

$wikiToc = [
    'sec-start'      => 'Démarrage',
    'sec-events'     => 'Événements',
    'sec-checkin'    => 'Check-in QR',
    'sec-sondages'   => 'Sondages',
    'sec-cafeteria'  => 'Cafétéria',
    'sec-jeux'       => 'Jeux',
    'sec-compta'     => 'Compta',
    'sec-couts'      => 'Coûts',
    'sec-caisse'     => 'Comptage caisse',
    'sec-reappro'    => 'Réappro',
    'sec-analytics'  => 'Analytics',
    'sec-caisses'    => 'Caisses',
    'sec-inventaire' => 'Inventaire',
    'sec-users'      => 'Utilisateurs',
    'sec-emails'     => 'Emails',
    'sec-settings'   => 'Paramètres',
    'sec-tips'       => 'Conseils',
];
?>
<div class="wiki">

<!-- ===================== HERO ===================== -->
<header class="wiki-hero">
    <span class="wiki-hero-emoji"></span>
    <h1>Guide de l'administrateur</h1>
    <p>Tout ce qu'il faut savoir pour gérer le site AEIC au quotidien.</p>
    <p class="wiki-hero-role" style="opacity:.75;font-size:.95em">Guide adapté à ton rôle — seules les sections qui te concernent sont affichées.</p>
</header>

<!-- ===================== RECHERCHE ===================== -->
<div class="wiki-search-wrap">
    <input type="text" id="wiki-search" placeholder="Rechercher..." autocomplete="off">
    <span id="wiki-count" class="wiki-count"></span>
</div>

<!-- ===================== SOMMAIRE ===================== -->
<nav class="wiki-toc" aria-label="Sommaire">
    <?php foreach ($wikiToc as $secId => $secLabel): ?>
        <?php if (!($wikiAccess[$secId] ?? false)) continue; ?>
        <a href="#<?= e($secId) ?>"><?= e($secLabel) ?></a>
    <?php endforeach; ?>
</nav>

<div class="wiki-body" id="wiki-body">

<?php if (!in_array(true, $wikiAccess, true)): ?>
<p class="muted">Aucune section ne concerne votre rôle pour le moment.</p>
<?php endif; ?>

<!-- ===================== 1. DÉMARRAGE ===================== -->
<?php if ($wikiAccess['sec-start'] ?? false): ?>
<section class="wiki-section" id="sec-start">
    <h2>Démarrage & connexion</h2>

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
        <p>Le 2FA est <strong>obligatoire</strong> pour le Fondateur, les administrateurs et les trésoriers. Il ajoute une couche de sécurité : même si quelqu'un vole ton mot de passe, il ne peut pas se connecter sans ton téléphone.</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-box">Email + mot de passe</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">Code à 6 chiffres (app Authenticator)</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box wiki-diagram-ok">Connecté</div>
        </div>
        <p><strong>Comment configurer :</strong> à la première connexion, un QR code s'affiche. Scanne-le avec <strong>Google Authenticator</strong> (Android/iOS). L'app génère un code à 6 chiffres qui change toutes les 30 secondes.</p>
    </div>

    <div class="wiki-block">
        <h3>Mot de passe oublié</h3>
        <p>Sur la page de connexion → <strong>« Mot de passe oublié ? »</strong> → entre ton email → un lien de réinitialisation t'est envoyé. Clique le lien → choisis un nouveau mot de passe (8 caractères min, 1 lettre + 1 chiffre).</p>
        <p>Un admin peut aussi faire un <strong>« Reset MDP »</strong> depuis Admin → Utilisateurs → ça génère un mot de passe temporaire envoyé par email.</p>
    </div>

    <div class="wiki-block">
        <h3>Les rôles</h3>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head">
                <span>Rôle</span><span>Accès</span><span>Qui ?</span>
            </div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-teal">FONDATEUR</span><span>Tout l'espace, y compris le groupe Système (Utilisateurs, Caisses, Inventaire, Coûts, Paramètres)</span><span>Fondateur de l'asso (rôle non modifiable depuis le site)</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-teal">ADMIN</span><span>Tous les modules ; groupe Système seulement si listé dans <code>SYSTEM_ADMINS</code></span><span>Président, membres du bureau de confiance</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-violet">TRESORERIE</span><span>Comptabilité complète + contenu, événements, cafétéria et jeux</span><span>Trésorier</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-violet">COMMUNICATION</span><span>Pages, équipe, sondages, promotions, médias + événements + cafétéria</span><span>Resp. communication</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-violet">CAFETERIA</span><span>Produits et catégories de la cafétéria</span><span>Resp. cafétéria</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-violet">JEUX</span><span>Jeux (Wordle, énigmes, classements) + cafétéria</span><span>Resp. jeux</span></div>
            <div class="wiki-table-row"><span class="wiki-tag wiki-tag-muted">ELEVE</span><span>Espace membre uniquement (pas d'admin)</span><span>Tous les étudiants inscrits</span></div>
        </div>
        <p><strong>Pages + :</strong> au-delà du rôle, le Fondateur peut attribuer des pages individuellement (Admin → Utilisateurs → « Pages ») : un membre du bureau accède alors à Inventaire, Caisses, Coûts de revient, Utilisateurs, Paramètres — ou à un module complet — sans changer de rôle.</p>
    </div>

    <div class="wiki-block">
        <h3>Changer son mot de passe</h3>
        <p>Une fois connecté → clique sur ton prénom en haut à droite → <strong>« Mes données »</strong> → <strong>« Changer mon mot de passe »</strong> → saisie l'ancien + le nouveau + confirmation → <strong>Modifier</strong>. Un email de confirmation est envoyé automatiquement.</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 2. ÉVÉNEMENTS ===================== -->
<?php if ($wikiAccess['sec-events'] ?? false): ?>
<section class="wiki-section" id="sec-events">
    <h2>Créer un événement</h2>

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
            <div class="wiki-diagram-box">Élève s'inscrit</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">Places restantes ?</div>
            <div class="wiki-arrow">↓ ↓</div>
            <div class="wiki-diagram-row">
                <div class="wiki-diagram-box wiki-diagram-ok">Oui → Inscription confirmée + QR code</div>
                <div class="wiki-diagram-box wiki-diagram-warn">Non → Liste d'attente (position X)</div>
            </div>
            <div class="wiki-arrow">↓ (si quelqu'un se désinscrit)</div>
            <div class="wiki-diagram-box wiki-diagram-ok">Premier de la file promu + email automatique</div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Gérer les inscriptions</h3>
        <p>Dans la liste des événements, clique l'icône → tu vois :</p>
        <ul class="wiki-list">
            <li>Liste des inscrits (nom, prénom, date d'inscription)</li>
            <li>Leurs choix (menus, options) si l'événement a des variantes</li>
            <li>Le statut de présence (/) si le check-in QR a été fait</li>
            <li>La <strong>liste d'attente</strong> en bas (si l'événement est complet)</li>
        </ul>
        <p>Bouton <strong>« Export CSV »</strong> pour télécharger la liste.</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 3. CHECK-IN QR ===================== -->
<?php if ($wikiAccess['sec-checkin'] ?? false): ?>
<section class="wiki-section" id="sec-checkin">
    <h2>Check-in QR (le jour J)</h2>

    <div class="wiki-block">
        <h3>Comment scanner les participants</h3>
        <p>Quand un élève s'inscrit à un événement, un <strong>QR code unique</strong> est généré. Il est visible sur la page de l'événement (si l'élève est connecté et inscrit).</p>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Le jour J</strong> → Admin → Événements → → <strong>« Ouvrir le check-in »</strong></div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Une page avec un <strong>champ de saisie</strong> s'ouvre (autofocus)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Scanne le QR</strong> du participant avec la caméra du téléphone (ou saisis le token manuellement) → Entrée</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Résultat : <span class="wiki-tag wiki-tag-green">Présent</span> ou <span class="wiki-tag wiki-tag-warn">Déjà checké</span></div></div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 4. SONDAGES ===================== -->
<?php if ($wikiAccess['sec-sondages'] ?? false): ?>
<section class="wiki-section" id="sec-sondages">
    <h2>Créer un sondage</h2>

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
<?php endif; ?>

<!-- ===================== 5. CAFÉTÉRIA ===================== -->
<?php if ($wikiAccess['sec-cafeteria'] ?? false): ?>
<section class="wiki-section" id="sec-cafeteria">
    <h2>Cafétéria — Produits & carte</h2>

    <div class="wiki-block">
        <h3>Ajouter un produit au menu</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Admin → Cafétéria → Produits</strong> → « + Nouveau produit »</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>Nom</strong> (ex: « Red Bull ») + <strong>Description</strong> (ex: « Boisson énergisante »)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Prix de vente</strong> (ex: <code>1,50</code>) + <strong>Catégorie</strong> (Boissons, Snacks...)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div><strong>Image</strong> (optionnel) : colle une URL d'une image uploadée dans Médias</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div><strong>Stock</strong> (pour le réappro) + <strong>Disponible</strong> + <strong>Actif</strong> </div></div>
            <div class="wiki-step"><span class="wiki-step-n">6</span><div>Enregistrer → le produit apparaît dans <strong>« Notre carte »</strong> sur l'accueil</div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Emojis automatiques</h3>
        <p>Si tu ne mets pas d'image, le site attribue automatiquement un emoji selon le nom :</p>
        <div class="wiki-emoji-grid">
            <span>Coca, Fanta, Oasis, Orangina</span>
            <span>Eau, Cristaline, Perrier</span>
            <span>Monster, Red Bull</span>
            <span>Bueno, KitKat, Mars, Snickers</span>
            <span>Chips</span>
            <span>Bonbon</span>
            <span>Lipton</span>
            <span>Minute Maid, Pulco</span>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 6. JEUX ===================== -->
<?php if ($wikiAccess['sec-jeux'] ?? false): ?>
<section class="wiki-section" id="sec-jeux">
    <h2>Jeux</h2>

    <div class="wiki-block">
        <h3>Gérer les jeux</h3>
        <p>Admin → Jeux : tout est regroupé en quatre pages :</p>
        <ul class="wiki-list">
            <li><strong>Vue d'ensemble</strong> : activité et statistiques des jeux</li>
            <li><strong>Joueurs &amp; Pseudos</strong> : corriger un pseudo ou réinitialiser un joueur (classements)</li>
            <li><strong>Mots Wordle</strong> : créer et modifier les mots proposés aux élèves</li>
            <li><strong>Énigmes</strong> : créer et modifier les énigmes et leurs réponses</li>
        </ul>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 7. COMPTABILITÉ ===================== -->
<?php if ($wikiAccess['sec-compta'] ?? false): ?>
<section class="wiki-section" id="sec-compta">
    <h2>Comptabilité — Importer SumUp</h2>

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
            <div class="wiki-diagram-box">Fichier CSV SumUp</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">Parse : dates FR, prix (virgule), moyen de paiement</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">Normalise : Visa/Mastercard → CARTE, Espèces → LIQUIDE</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box">Déduplication : clé unique (ref + date + produit). Réimport = 0 doublon.</div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box wiki-diagram-ok">Ventes disponibles dans tout le module compta</div>
        </div>
        <p>L'historique de la page garde les <strong>50 derniers imports manuels</strong>. La <strong>synchro API SumUp</strong> (automatique, toutes les minutes) est affichée à part et <strong>regroupée par jour</strong> : clique un jour pour voir le détail des passages.</p>
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
        <p>La <strong>catégorie</strong> de chaque alias (Boisson, Nourriture, Événement...) est éditable dans <strong>« Alias existants »</strong> et se <strong>synchronise sur les ventes déjà importées</strong> — indispensable pour des Analytics et un réappro fiables.</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 8. COÛTS DE REVIENT ===================== -->
<?php if ($wikiAccess['sec-couts'] ?? false): ?>
<section class="wiki-section" id="sec-couts">
    <h2>Coûts de revient (bénéfice réel)</h2>

    <div class="wiki-block">
        <p>Le bénéfice = <strong>prix de vente − coût d'achat</strong>. Sans coût saisi → marge à 100% (faux).</p>
        <p>Page du groupe <strong>Système</strong> (Admin → Système → Coûts de revient) : réservée au Fondateur, aux ADMIN explicitement autorisés, ou à qui la page a été attribuée (« Pages + »).</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-box">Prix de vente TTC : 1,00 €</div>
            <div class="wiki-arrow">−</div>
            <div class="wiki-diagram-box">Coût d'achat : 0,60 €</div>
            <div class="wiki-arrow">=</div>
            <div class="wiki-diagram-box wiki-diagram-ok">Bénéfice : 0,40 € (marge 40%)</div>
        </div>
        <p><strong>Comment :</strong> recherche le produit → saisis le coût → Enregistrer. Si le prix d'achat change → crée un nouveau lot daté (l'historique des coûts est conservé).</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 9. COMPTAGE DE CAISSE ===================== -->
<?php if ($wikiAccess['sec-caisse'] ?? false): ?>
<section class="wiki-section" id="sec-caisse">
    <h2>Comptage de caisse</h2>

    <div class="wiki-block">
        <p>Accessible à <strong>tout le bureau</strong> (hors élèves), même sans accès comptabilité : Admin → Comptabilité → <strong>Comptage caisse</strong>.</p>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Compte physiquement</strong> le liquide présent dans la caisse</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Saisis le <strong>montant compté</strong> + une note optionnelle (ex: « comptage après soirée »)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>Enregistre → l'<strong>écart</strong> est révélé immédiatement</div></div>
        </div>
        <p>Le comptage est <strong>« à l'aveugle »</strong> : le montant théorique n'est volontairement pas affiché avant la saisie, pour un comptage honnête. Aucun historique sur cette page — la traçabilité complète (écarts, dépôts, ajustements) est dans <strong>Système → Caisses</strong>.</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 10. RÉAPPRO ===================== -->
<?php if ($wikiAccess['sec-reappro'] ?? false): ?>
<section class="wiki-section" id="sec-reappro">
    <h2>Réapprovisionnement</h2>

    <div class="wiki-block">
        <h3>Comment savoir combien racheter</h3>
        <p>La page est en <strong>lecture seule</strong> : plus aucune saisie de stock ici. Le stock affiché est le <strong>théorique de l'inventaire</strong> (dernier comptage + achats − ventes − pertes) : il suit automatiquement chaque mouvement.</p>
        <div class="wiki-diagram">
            <div class="wiki-diagram-row">
                <div class="wiki-diagram-box">Ventes réelles<br>(période analysée au choix)</div>
                <div class="wiki-diagram-box">Jours d'ouverture<br>(lun-ven = 22j/mois)</div>
                <div class="wiki-diagram-box">Stock théorique<br>(issu de l'inventaire)</div>
            </div>
            <div class="wiki-arrow">↓</div>
            <div class="wiki-diagram-box wiki-diagram-ok">« À commander : X unités »</div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Utilisation</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>Choisis la <strong>période analysée</strong> (7 j, 30 j, 3 mois... ou dates personnalisées)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Choisis l'<strong>horizon à couvrir</strong> (1 semaine, 1 mois...) : la colonne <strong>« À commander »</strong> se recalcule automatiquement</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>Suis l'<strong>autonomie</strong> de chaque produit (jours d'ouverture avant rupture) — pastille rouge si &lt; 7 jours</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div>Le <strong>total en bas</strong> donne la quantité globale à commander et le <strong>coût estimé du panier</strong> (× coût de revient du lot en cours)</div></div>
        </div>
        <p>Un produit <strong>jamais compté</strong> apparaît « à compter » : son besoin est calculé sans stock déduit. Fais l'inventaire régulièrement (voir <strong>Inventaire</strong>) pour fiabiliser l'analyse.</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 11. ANALYTICS ===================== -->
<?php if ($wikiAccess['sec-analytics'] ?? false): ?>
<section class="wiki-section" id="sec-analytics">
    <h2>Dashboard Analytics</h2>

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
<?php endif; ?>

<!-- ===================== 11. CAISSES (SYSTÈME) ===================== -->
<?php if ($wikiAccess['sec-caisses'] ?? false): ?>
<section class="wiki-section" id="sec-caisses">
    <h2>Caisses — traçabilité du liquide</h2>

    <div class="wiki-block">
        <p>Page du groupe <strong>Système</strong> (Admin → Système → Caisses) : elle retrace tout le liquide, du comptage au dépôt en banque.</p>
        <ul class="wiki-list">
            <li><strong>Solde théorique</strong> = ventes en espèces + mouvements manuels (fond de caisse, dépôts, ajustements)</li>
            <li><strong>Comptage physique</strong> : saisis le montant compté → l'<strong>écart</strong> est historisé puis un ajustement réaligne le théorique</li>
            <li><strong>Écarts détectés</strong> (30 derniers jours) : vue rapide des anomalies</li>
            <li><strong>Dépôt à la banque</strong> : montant, date et n° de bordereau</li>
            <li><strong>Fond de caisse</strong> : le montant permanent laissé en caisse</li>
            <li><strong>Historiques</strong> : tous les mouvements et tous les comptages (compté / théorique / écart / par qui)</li>
        </ul>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 12. INVENTAIRE (SYSTÈME) ===================== -->
<?php if ($wikiAccess['sec-inventaire'] ?? false): ?>
<section class="wiki-section" id="sec-inventaire">
    <h2>Inventaire</h2>

    <div class="wiki-block">
        <p>Page du groupe <strong>Système</strong> (Admin → Système → Inventaire) : tu comptes le stock <strong>physique</strong> et le site le compare au <strong>théorique</strong> (dernier comptage + achats − ventes). Un écart = perte, casse, offert ou erreur de saisie.</p>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div>Compte physiquement les produits (seules les lignes renseignées sont comptées)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div>Enregistre → les <strong>écarts</strong> sont calculés et conservés (historique des comptages)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div>Chaque comptage devient le <strong>nouveau point de départ</strong> du stock théorique</div></div>
        </div>
        <p>Le stock théorique alimente directement le <strong>Réappro</strong> (quantités à commander) : un inventaire régulier = un réappro fiable.</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 13. UTILISATEURS ===================== -->
<?php if ($wikiAccess['sec-users'] ?? false): ?>
<section class="wiki-section" id="sec-users">
    <h2>Utilisateurs & adhésions</h2>

    <div class="wiki-block">
        <h3>Actions possibles sur un utilisateur</h3>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head"><span>Action</span><span>Effet</span></div>
            <div class="wiki-table-row"><span><strong>Changer le rôle</strong></span><span>ELEVE → ADMIN, TRESORERIE, COMMUNICATION, CAFETERIA ou JEUX (le rôle Fondateur n'est jamais attribuable depuis le site)</span></div>
            <div class="wiki-table-row"><span><strong>Pages</strong></span><span>Attribue des pages individuellement, au-delà du rôle : modules complets (Comptabilité, Événements...) ou pages Système (Inventaire, Coûts, Caisses, Utilisateurs, Paramètres)</span></div>
            <div class="wiki-table-row"><span><strong>Renommer</strong></span><span>Prénom et nom modifiables en place (clique hors du champ ou Entrée pour enregistrer)</span></div>
            <div class="wiki-table-row"><span><strong>Désactiver</strong></span><span>Bloque la connexion. Données conservées.</span></div>
            <div class="wiki-table-row"><span><strong>Reset MDP</strong></span><span>Génère un mot de passe temporaire envoyé par email.</span></div>
            <div class="wiki-table-row"><span><strong>Supprimer</strong></span><span>Anonymise les données (RGPD). Comptabilité conservée anonyme.</span></div>
        </div>
        <p>Les <strong>adhésions</strong> se gèrent dans Admin → Adhésions (créer une cotisation, « Marquer payée »).</p>
    </div>

    <div class="wiki-block">
        <h3>Sécurité</h3>
        <div class="wiki-alert wiki-alert-warn">
            <strong>Règles de sécurité :</strong>
            <ul class="wiki-list">
                <li>Tu ne peux <strong>pas</strong> supprimer ton propre compte depuis l'admin</li>
                <li>Tu ne peux <strong>pas</strong> supprimer/rétrograder le dernier administrateur</li>
                <li>Le <strong>2FA est obligatoire</strong> pour FONDATEUR, ADMIN et TRESORERIE</li>
                <li>Le <strong>retrait du rôle Trésorerie</strong> et les <strong>Pages</strong> des comptes Fondateur/Trésorerie sont réservés au Fondateur</li>
                <li>Chaque action est <strong>journalisée</strong> (audit log visible dans le tableau de bord)</li>
            </ul>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 14. EMAILS ===================== -->
<?php if ($wikiAccess['sec-emails'] ?? false): ?>
<section class="wiki-section" id="sec-emails">
    <h2>Emails automatiques</h2>

    <div class="wiki-block">
        <p>Le site envoie automatiquement ces emails (si le SMTP/API Brevo est configuré) :</p>
        <div class="wiki-table">
            <div class="wiki-table-row wiki-table-head"><span>Déclencheur</span><span>Email envoyé</span></div>
            <div class="wiki-table-row"><span>Inscription d'un élève</span><span>Mot de passe temporaire (« Bienvenue à l'AEIC »)</span></div>
            <div class="wiki-table-row"><span>24h avant événement</span><span>« Plus que 24h ! » avec détails (date, lieu)</span></div>
            <div class="wiki-table-row"><span>1h avant événement</span><span>« Ça commence dans 1h ! »</span></div>
            <div class="wiki-table-row"><span>Reset MDP (admin)</span><span>Mot de passe temporaire à l'utilisateur</span></div>
            <div class="wiki-table-row"><span>Changement de mot de passe</span><span>« Votre mot de passe a été modifié »</span></div>
            <div class="wiki-table-row"><span>Suppression de compte</span><span>Confirmation RGPD (anonymisation)</span></div>
            <div class="wiki-table-row"><span>Liste d'attente promue</span><span>« Une place s'est libérée ! Vous êtes inscrit »</span></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Vérifier que les emails partent</h3>
        <p>Admin → Paramètres → Emails/SMTP → <strong>« Envoyer un e-mail de test »</strong> → tape ton adresse → si tu reçois l'email → tout marche.</p>
        <p>Si échec → vérifie la <strong>clé API Brevo</strong> et l'<strong>adresse d'expédition</strong> (doit être un domaine vérifié comme <code>contact@aremond.ovh</code>).</p>
    </div>
</section>
<?php endif; ?>

<!-- ===================== 15. PARAMÈTRES ===================== -->
<?php if ($wikiAccess['sec-settings'] ?? false): ?>
<section class="wiki-section" id="sec-settings">
    <h2>Paramètres du site</h2>

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
<?php endif; ?>

<!-- ===================== 16. CONSEILS ===================== -->
<?php if ($wikiAccess['sec-tips'] ?? false): ?>
<section class="wiki-section" id="sec-tips">
    <h2>Conseils pratiques</h2>

    <div class="wiki-block">
        <h3>Routine mensuelle (trésorier)</h3>
        <div class="wiki-steps">
            <div class="wiki-step"><span class="wiki-step-n">1</span><div><strong>Importe</strong> le rapport SumUp du mois écoulé (ou laisse la synchro API faire)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">2</span><div><strong>Mappe</strong> les nouveaux libellés non reconnus (aliases + catégories)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">3</span><div><strong>Saisis</strong> les coûts de revient des nouveaux produits (si tu as l'accès Coûts)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">4</span><div><strong>Fais l'inventaire</strong> (stock physique) pour fiabiliser le stock théorique</div></div>
            <div class="wiki-step"><span class="wiki-step-n">5</span><div><strong>Vérifie</strong> le réappro (quantités à commander, autonomie)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">6</span><div><strong>Compte la caisse</strong> puis dépôts du liquide en banque (Système → Caisses)</div></div>
            <div class="wiki-step"><span class="wiki-step-n">7</span><div><strong>Analyse</strong> le dashboard Analytics (tendances, insights)</div></div>
        </div>
    </div>

    <div class="wiki-block">
        <h3>Routine début d'année (président)</h3>
        <ul class="wiki-list">
            <li>Crée les comptes pour les nouveaux membres du bureau</li>
            <li>Donne le rôle ADMIN aux nouveaux (2FA obligatoire)</li>
            <li>Attribue des <strong>Pages +</strong> si quelqu'un gère une page précise (inventaire, caisses...)</li>
            <li>Crée les adhésions pour la nouvelle saison</li>
            <li>Mets à jour la page Équipe (nouveaux membres, photos)</li>
            <li>Crée les événements de rentrée</li>
        </ul>
    </div>

    <div class="wiki-block">
        <h3>Si quelque chose ne marche pas</h3>
        <div class="wiki-alert wiki-alert-info">
            <strong>Dépannage rapide :</strong>
            <ul class="wiki-list">
                <li><strong>Page blanche / erreur 500</strong> → contacte Remond Adrien (développeur)</li>
                <li><strong>Emails ne partent pas</strong> → vérifier Paramètres → Brevo API key + adresse d'expédition</li>
                <li><strong>Chiffres à 0</strong> → importer un rapport SumUp (Comptabilité → Importer CSV)</li>
                <li><strong>Marges à 100%</strong> → saisir les coûts de revient (Système → Coûts de revient)</li>
                <li><strong>Réappro peu fiable</strong> → faire l'inventaire (Système → Inventaire)</li>
                <li><strong>QR code ne marche pas</strong> → l'élève doit se désinscrire puis se réinscrire</li>
            </ul>
        </div>
    </div>
</section>
<?php endif; ?>

</div><!-- /wiki-body -->

<div class="wiki-footer">
    <p>Développé par <strong style="color:var(--primary)">Remond Adrien</strong> · © 2026 AEIC · 100 % étudiant</p>
</div>

</div><!-- /wiki -->

<style>
.wiki { display: flex; flex-direction: column; gap: 1.25rem; }

/* Hero */
.wiki-hero {
    position: relative; text-align: center;
    padding: 2rem 1.5rem 1.75rem;
    background:
        radial-gradient(900px 180px at 50% -40px, rgba(72,189,211,0.14), transparent),
        linear-gradient(135deg, rgba(72,189,211,0.05), rgba(97,80,170,0.05));
    border: 1px solid var(--border); border-radius: 18px;
    overflow: hidden;
}
.wiki-hero::before {
    content: ''; position: absolute; inset: 0 0 auto 0; height: 3px;
    background: linear-gradient(90deg, transparent, var(--primary), transparent);
}
.wiki-hero-emoji { font-size: 2.4rem; display: block; }
.wiki-hero h1 {
    font-size: 1.7rem; font-weight: 900; margin: 0.4rem 0 0.3rem;
    color: var(--primary); text-transform: none; letter-spacing: -0.02em;
}
.wiki-hero p { color: var(--muted); font-size: 0.92rem; margin: 0; }

/* Recherche */
.wiki-search-wrap {
    position: sticky; top: 0; z-index: 20;
    display: flex; align-items: center; gap: 0.75rem;
    background: var(--admin-bg, #0a1b33);
    backdrop-filter: blur(10px);
    padding: 0.5rem 0;
}
.wiki-search-wrap input {
    flex: 1; background: rgba(255,255,255,0.04);
    border: 1px solid var(--border); border-radius: 999px;
    color: var(--foreground); padding: 0.6rem 1.2rem; font-size: 0.92rem;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.wiki-search-wrap input:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(72,189,211,0.15);
}
.wiki-count { font-size: 0.78rem; color: var(--muted); white-space: nowrap; }

/* Sommaire */
.wiki-toc {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 0.5rem;
}
.wiki-toc a {
    display: flex; align-items: center; gap: 0.45rem;
    padding: 0.55rem 0.8rem;
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border); border-radius: 10px;
    color: var(--muted); font-size: 0.82rem; font-weight: 600;
    text-decoration: none;
    transition: color .15s ease, border-color .15s ease, background .15s ease, transform .15s ease;
}
.wiki-toc a:hover {
    color: var(--primary); border-color: rgba(72,189,211,0.5);
    background: rgba(72,189,211,0.06);
    transform: translateY(-1px);
}

/* Sections (pleine largeur, 1 par ligne, numérotées via compteur CSS) */
.wiki-body { display: flex; flex-direction: column; gap: 1.25rem; counter-reset: wikiSec; }

.wiki-section {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border); border-radius: 16px;
    overflow: hidden;
    scroll-margin-top: 5rem;
    counter-increment: wikiSec;
    transition: border-color .2s ease;
}
.wiki-section:target { border-color: rgba(72,189,211,0.5); }
.wiki-section h2 {
    display: flex; align-items: center; gap: 0.6rem;
    font-size: 1.15rem; font-weight: 800; margin: 0;
    padding: 1.1rem 1.4rem;
    background: linear-gradient(180deg, rgba(72,189,211,0.07), rgba(72,189,211,0.02));
    border-bottom: 1px solid var(--border);
    color: var(--foreground); text-transform: none; letter-spacing: -0.01em;
}
.wiki-section h2::after {
    content: counter(wikiSec, decimal-leading-zero);
    margin-left: auto; flex-shrink: 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.72rem; font-weight: 700;
    color: var(--primary); opacity: 0.65;
    border: 1px solid var(--border); border-radius: 6px;
    padding: 0.15rem 0.45rem;
}

.wiki-block { padding: 1.2rem 1.4rem; border-bottom: 1px solid rgba(255,255,255,0.04); }
.wiki-block:last-child { border-bottom: none; }
.wiki-block h3 {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.95rem; font-weight: 800; margin: 0 0 0.7rem;
    color: var(--foreground); text-transform: none;
}
.wiki-block h3::before {
    content: ''; width: 8px; height: 8px; border-radius: 3px;
    background: linear-gradient(135deg, var(--primary), var(--secondary, #6150aa));
    flex-shrink: 0;
}
.wiki-block p { font-size: 0.89rem; color: var(--muted); line-height: 1.65; margin: 0 0 0.6rem; }
.wiki-block p:last-child { margin-bottom: 0; }
.wiki-block p strong, .wiki-list strong { color: var(--foreground); }
.wiki-block code, .wiki-step code, .wiki-diagram-box code {
    background: rgba(72,189,211,0.1); color: var(--primary);
    padding: 0.12rem 0.4rem; border-radius: 5px; font-size: 0.8rem;
}

/* Listes */
.wiki-list { list-style: none; padding: 0; margin: 0.5rem 0; display: flex; flex-direction: column; gap: 0.3rem; }
.wiki-list li {
    font-size: 0.88rem; color: var(--muted); line-height: 1.55;
    padding: 0.35rem 0 0.35rem 1.3rem; position: relative;
}
.wiki-list li::before {
    content: '›'; position: absolute; left: 0.15rem;
    color: var(--primary); font-weight: 800;
}

/* Steps (numérotés, 1 par ligne) */
.wiki-steps { display: flex; flex-direction: column; gap: 0.5rem; margin: 0.75rem 0; }
.wiki-step {
    display: flex; align-items: flex-start; gap: 0.8rem;
    padding: 0.7rem 0.9rem;
    background: rgba(255,255,255,0.025);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 10px;
    transition: border-color .15s ease, background .15s ease;
}
.wiki-step:hover { border-color: rgba(72,189,211,0.4); background: rgba(72,189,211,0.04); }
.wiki-step-n {
    display: inline-grid; place-items: center; flex-shrink: 0;
    width: 24px; height: 24px; margin-top: 0.1rem;
    border-radius: 999px;
    background: rgba(72,189,211,0.14); color: var(--primary);
    border: 1px solid rgba(72,189,211,0.35);
    font-size: 0.75rem; font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.wiki-step div { font-size: 0.88rem; color: var(--muted); line-height: 1.55; }
.wiki-step div strong { color: var(--foreground); }

/* Diagrammes */
.wiki-diagram {
    display: flex; flex-direction: column; align-items: center; gap: 0.35rem;
    padding: 1.25rem 1rem; margin: 0.75rem 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.14), rgba(0,0,0,0.05));
    border: 1px dashed rgba(255,255,255,0.09);
    border-radius: 12px;
}
.wiki-diagram-box {
    background: rgba(255,255,255,0.04); border: 1px solid var(--border);
    border-radius: 10px; padding: 0.55rem 1.1rem;
    font-size: 0.84rem; color: var(--foreground); text-align: center;
    min-width: 200px; max-width: 420px; line-height: 1.45;
}
.wiki-diagram-ok { border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.08); }
.wiki-diagram-warn { border-color: rgba(245,158,11,0.35); background: rgba(245,158,11,0.08); }
.wiki-arrow { display: grid; place-items: center; color: var(--primary); font-size: 0.85rem; line-height: 1.2; }
.wiki-diagram-row { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; }

/* Tableaux : vraies colonnes alignées, quel que soit le nombre de cellules */
.wiki-table {
    display: flex; flex-direction: column; margin: 0.75rem 0;
    border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
}
.wiki-table-row {
    display: flex; align-items: center; gap: 1rem;
    padding: 0.65rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 0.85rem; color: var(--muted);
}
.wiki-table-row:last-child { border-bottom: none; }
.wiki-table-row:nth-child(even):not(.wiki-table-head) { background: rgba(255,255,255,0.015); }
.wiki-table-row > span { flex: 1 1 0; min-width: 0; line-height: 1.5; }
.wiki-table-row > span:first-child { flex: 0 0 30%; color: var(--foreground); font-weight: 600; }
.wiki-table-head {
    background: rgba(72,189,211,0.06);
    font-size: 0.7rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--primary); padding: 0.55rem 1rem;
}
@media (max-width: 600px) {
    .wiki-table-row { flex-wrap: wrap; gap: 0.25rem 0.75rem; }
    .wiki-table-row > span:first-child { flex: 1 1 100%; }
}

/* Tags */
.wiki-tag {
    display: inline-block; padding: 0.2rem 0.55rem;
    border-radius: 999px; border: 1px solid transparent;
    font-size: 0.72rem; font-weight: 800; letter-spacing: 0.03em;
}
.wiki-tag-teal   { background: rgba(72,189,211,0.12); color: var(--primary); border-color: rgba(72,189,211,0.3); }
.wiki-tag-violet { background: rgba(97,80,170,0.12); color: var(--secondary); border-color: rgba(97,80,170,0.35); }
.wiki-tag-muted  { background: rgba(255,255,255,0.04); color: var(--muted); border-color: var(--border); }
.wiki-tag-green  { background: rgba(34,197,94,0.1); color: #4ade80; border-color: rgba(34,197,94,0.3); }
.wiki-tag-warn   { background: rgba(245,158,11,0.1); color: #fbbf24; border-color: rgba(245,158,11,0.3); }

/* Alertes */
.wiki-alert {
    padding: 0.9rem 1.1rem; border-radius: 12px;
    border: 1px solid transparent; border-left: 4px solid;
    font-size: 0.87rem; color: var(--muted); line-height: 1.6;
}
.wiki-alert strong { color: var(--foreground); }
.wiki-alert-warn { border-color: rgba(245,158,11,0.25); border-left-color: #f59e0b; background: rgba(245,158,11,0.05); }
.wiki-alert-info { border-color: rgba(72,189,211,0.25); border-left-color: var(--primary); background: rgba(72,189,211,0.05); }

/* Emoji grid */
.wiki-emoji-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.5rem; margin: 0.75rem 0;
}
.wiki-emoji-grid span {
    background: rgba(255,255,255,0.025);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 10px; padding: 0.5rem 0.8rem;
    font-size: 0.82rem; color: var(--muted);
    transition: border-color .15s ease, color .15s ease;
}
.wiki-emoji-grid span:hover { border-color: rgba(72,189,211,0.4); color: var(--foreground); }

/* Footer */
.wiki-footer { text-align: center; padding: 1.5rem 0; border-top: 1px solid var(--border); }
.wiki-footer p { font-size: 0.82rem; color: var(--muted); margin: 0; }

/* Recherche */
.wiki-block.is-hidden { display: none; }
.wiki-section.is-hidden { display: none; }

/* Mobile */
@media (max-width: 640px) {
    .wiki-hero { padding: 1.5rem 1rem 1.25rem; }
    .wiki-section h2 { font-size: 1.05rem; padding: 1rem 1.1rem; }
    .wiki-block { padding: 1rem 1.1rem; }
    .wiki-diagram-box { min-width: 140px; }
}
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
