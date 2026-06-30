<?php

declare(strict_types=1);

/**
 * Wiki / Guide de l'administrateur — intégré au site.
 *
 * @var array<string,mixed> $user
 */
$sections = [
    'events' => [
        'icon' => '📅',
        'title' => 'Gérer les événements',
        'items' => [
            'Créer un événement' => 'Admin → Événements → "+ Nouvel événement". Remplis titre, date, lieu, catégorie, description. Coche "Publié" pour l\'afficher.',
            'Capacité max & liste d\'attente' => 'Si tu mets une capacité max, les inscriptions supplémentaires vont en file d\'attente. Une désinscription promeut automatiquement le suivant (+ email).',
            'Carte interactive' => 'Coche "Afficher une carte" → le lieu est géocodé automatiquement et une carte s\'affiche sur la page de l\'événement.',
            'Inscriptions' => 'Clique 📋 sur un événement pour voir les inscrits + exporter en CSV. Tu peux aussi promouvoir manuellement quelqu\'un de la file d\'attente.',
            'Check-in QR' => 'Le jour J : Événements → Inscriptions → "Ouvrir le check-in" → scanne le QR de chaque participant → ✅ présent.',
        ],
    ],
    'sondages' => [
        'icon' => '📊',
        'title' => 'Gérer les sondages',
        'items' => [
            'Créer un sondage' => 'Admin → Sondages → "+ Nouveau". Ajoute des options (bouton "+ Ajouter"). Choix unique ou multiple.',
            'Résultats' => 'Les résultats sont visibles par tous après le vote. L\'option gagnante a un 🏆.',
            'Vote unique' => 'Chaque membre ne peut voter qu\'une seule fois. Non connecté → invitation à se connecter.',
        ],
    ],
    'cafeteria' => [
        'icon' => '☕',
        'title' => 'La cafétéria (produits & carte)',
        'items' => [
            'Ajouter un produit' => 'Admin → Cafétéria → Produits → "+ Nouveau". Remplis nom, prix, catégorie, stock.',
            'Emojis automatiques' => 'Le site attribue un emoji selon le nom (Coca→🥤, Bueno→🍫, Monster→⚡). Ajoute une image pour remplacer l\'emoji.',
            'Catégories' => 'Admin → Cafétéria → Catégories. Crée/modifie les catégories (Boissons, Snacks...). L\'ordre détermine les onglets du menu.',
            'Affichage sur l\'accueil' => 'Les produits "Actifs" et "Disponibles" apparaissent dans "Notre carte" sur la page d\'accueil.',
        ],
    ],
    'compta' => [
        'icon' => '💰',
        'title' => 'La comptabilité (SumUp)',
        'items' => [
            'Importer un rapport' => 'Admin → Comptabilité → Importer CSV → choisis le fichier SumUp. Le système déduplique automatiquement (réimport = 0 doublon).',
            'Mapping des libellés' => 'SumUp utilise des noms différents pour un même produit. "Auto-détecter les doublons" → vérifier → Appliquer.',
            'Coûts de revient' => 'Comptabilité → Coûts de revient. Saisis le prix d\'achat de chaque produit pour calculer le vrai bénéfice. Sans coût → marge affichée à 100% (faux).',
            'Bénéfice par produit' => 'Comptabilité → Produits. Tri par CA, bénéfice, marge. Filtre par catégorie.',
        ],
    ],
    'reappro' => [
        'icon' => '📦',
        'title' => 'Le réapprovisionnement',
        'items' => [
            'Principe' => 'La page calcule combien racheter de chaque produit, basé sur les ventes réelles (moyenne mobile 3 mois).',
            'Utilisation' => 'Choisis la période (1 semaine, 1 mois...). Saisis le stock actuel → "Enregistrer". La colonne "À commander" se recalcule.',
            'Jours d\'ouverture' => 'La cafétéria est ouverte du lundi au vendredi (5j/sem ≈ 22j/mois). Les calculs sont basés sur les jours d\'ouverture.',
        ],
    ],
    'analytics' => [
        'icon' => '📈',
        'title' => 'Le dashboard Analytics',
        'items' => [
            'Filtres' => 'Période (7j à 12 mois), granularité (jour/semaine/mois), catégorie, moyen de paiement. Les filtres sont partageables par URL.',
            '6 KPI' => 'CA, bénéfice, volume, panier moyen, transactions, nouveaux membres — avec variation vs période précédente.',
            'Heatmap' => 'Grille 7 jours × 24h. Survol d\'une case → tooltip avec le CA de l\'heure + total du jour.',
            'Insights' => 'Produit star, plus forte croissance, alerte marge, meilleur jour — calculés automatiquement.',
        ],
    ],
    'users' => [
        'icon' => '👥',
        'title' => 'Utilisateurs & adhésions',
        'items' => [
            'Changer un rôle' => 'Sélectionne ADMIN, TRESORERIE ou ELEVE. Chaque changement est journalisé + email de notification envoyé.',
            'Reset MDP' => 'Bouton "Reset MDP" → génère un mot de passe temporaire envoyé par email à l\'utilisateur.',
            'Supprimer un compte' => 'Anonymise les données perso (RGPD). Les données comptables sont conservées mais déliées de l\'identité.',
            'Cotisations' => 'Admin → Adhésions. Marque "Payée" avec le montant. Filtre par saison (2025-2026, etc.).',
            'Sécurité' => 'Impossible de se supprimer soi-même ou de supprimer le dernier admin. Le 2FA est obligatoire pour les admins.',
        ],
    ],
    'promotions' => [
        'icon' => '🏷️',
        'title' => 'Promotions',
        'items' => [
            'Créer une promo' => 'Admin → Promotions → "+ Nouvelle". Remplis titre, ancien/nouveau prix, badge (PROMO, -20%...).',
            'Affichage' => 'Les promos actives apparaissent sur l\'accueil dans "Promos & ventes spéciales". Ancien prix barré, nouveau prix en teal.',
        ],
    ],
    'settings' => [
        'icon' => '⚙️',
        'title' => 'Paramètres du site',
        'items' => [
            'Emails / SMTP' => 'Renseigne la clé API Brevo (recommandé). Test avec "Envoyer un e-mail de test". Sans config → les liens s\'affichent à l\'écran (dev only).',
            'SumUp' => 'Colle ton lien de paiement SumUp + active pour afficher les boutons "Payer en ligne".',
            'Discord' => 'Colle l\'URL du webhook Discord + active. Un message est envoyé à chaque nouvel événement/sondage.',
            'Mode maintenance' => 'Bloque le site public (seul l\'admin garde l\'accès).',
            'Contact & carte' => 'Adresse + coordonnées GPS pour la carte "Où nous trouver".',
        ],
    ],
    'emails' => [
        'icon' => '📧',
        'title' => 'Emails automatiques',
        'items' => [
            'Inscription' => 'Un mot de passe temporaire est envoyé par email au nouvel inscrit.',
            'Rappel 24h' => 'Automatique (cron 15min) → email aux inscrits 24h avant l\'événement.',
            'Rappel 1h' => 'Automatique → email aux inscrits 1h avant l\'événement.',
            'Reset MDP admin' => 'Le mot de passe temporaire est envoyé à l\'utilisateur concerné.',
            'Suppression de compte' => 'Email de confirmation RGPD envoyé avant anonymisation.',
        ],
    ],
    'medias' => [
        'icon' => '🖼️',
        'title' => 'Médias & pages',
        'items' => [
            'Uploader une image' => 'Admin → Médias → glisse-dépose ou clique. Copie l\'URL avec le bouton "Copier l\'URL".',
            'Pages CMS' => 'Admin → Pages → modifie mentions légales, confidentialité, CGU. Le contenu est du HTML.',
            'Équipe' => 'Admin → Équipe → ajoute les membres du bureau (photo, rôle, pôle).',
        ],
    ],
];
?>
<div class="wiki-page">
    <div class="wiki-head">
        <p class="eyebrow">📚 Documentation</p>
        <h1 class="page-title">Wiki — Guide de l'admin</h1>
        <p class="muted">Tout ce qu'il faut savoir pour gérer le site AEIC. Destiné à tous les membres du bureau.</p>
    </div>

    <!-- Recherche -->
    <div class="wiki-search-bar">
        <input type="text" id="wiki-search" placeholder="🔎 Rechercher dans le guide..." autocomplete="off">
    </div>

    <!-- Sommaire -->
    <div class="wiki-toc">
        <?php foreach ($sections as $key => $sec): ?>
            <a href="#wiki-<?= e($key) ?>" class="wiki-toc-link">
                <span class="wiki-toc-icon"><?= e($sec['icon']) ?></span>
                <span><?= e($sec['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Sections -->
    <div class="wiki-content">
        <?php foreach ($sections as $key => $sec): ?>
            <section class="wiki-section" id="wiki-<?= e($key) ?>" data-search="<?= e(strtolower($sec['title'] . ' ' . implode(' ', array_keys($sec['items'])) . ' ' . implode(' ', array_values($sec['items'])))) ?>">
                <div class="wiki-section-head">
                    <span class="wiki-section-icon"><?= e($sec['icon']) ?></span>
                    <h2 class="wiki-section-title"><?= e($sec['title']) ?></h2>
                </div>
                <div class="wiki-items">
                    <?php foreach ($sec['items'] as $question => $answer): ?>
                        <div class="wiki-item" data-search="<?= e(strtolower($question . ' ' . $answer)) ?>">
                            <h3 class="wiki-item-q"><?= e($question) ?></h3>
                            <p class="wiki-item-a"><?= e($answer) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="wiki-footer">
        <p class="muted">💻 Développé par <strong style="color:var(--primary)">Remond Adrien</strong> · © 2026 AEIC</p>
    </div>
</div>

<style>
.wiki-page { display: flex; flex-direction: column; gap: 1.5rem; }
.wiki-head .page-title { font-size: 1.8rem; margin: 0.25rem 0; }

.wiki-search-bar input {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--foreground);
    padding: 0.7rem 1rem;
    font-size: 0.95rem;
}
.wiki-search-bar input:focus { outline: none; border-color: var(--primary); }

.wiki-toc {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.wiki-toc-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 0.4rem 0.85rem;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--muted);
    text-decoration: none;
    transition: all 0.15s;
}
.wiki-toc-link:hover {
    border-color: var(--primary);
    color: var(--foreground);
    background: rgba(72,189,211,0.06);
}
.wiki-toc-icon { font-size: 1rem; }

.wiki-content { display: flex; flex-direction: column; gap: 1.5rem; }

.wiki-section {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 1.5rem;
}
.wiki-section-head {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border);
}
.wiki-section-icon { font-size: 1.6rem; }
.wiki-section-title { font-size: 1.15rem; font-weight: 800; margin: 0; color: var(--primary); }

.wiki-items { display: flex; flex-direction: column; gap: 1rem; }
.wiki-item { padding-left: 1rem; border-left: 3px solid rgba(72,189,211,0.2); }
.wiki-item:hover { border-left-color: var(--primary); }
.wiki-item-q { font-size: 0.95rem; font-weight: 700; margin: 0 0 0.3rem; color: var(--foreground); }
.wiki-item-a { font-size: 0.88rem; color: var(--muted); margin: 0; line-height: 1.6; }

.wiki-footer { text-align: center; padding: 1.5rem 0 0.5rem; border-top: 1px solid var(--border); }
.wiki-footer .muted { font-size: 0.82rem; margin: 0; }

/* Recherche : masque les items non trouvés */
.wiki-item.is-hidden { display: none; }
.wiki-section.is-hidden { display: none; }

@media (max-width: 640px) {
    .wiki-toc { flex-direction: column; }
    .wiki-section { padding: 1rem; }
}
</style>

<script>
(function () {
    var search = document.getElementById('wiki-search');
    if (!search) return;
    function norm(s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    search.addEventListener('input', function () {
        var q = norm(search.value.trim());
        var sections = document.querySelectorAll('.wiki-section');
        sections.forEach(function (sec) {
            var items = sec.querySelectorAll('.wiki-item');
            var anyVisible = false;
            items.forEach(function (item) {
                var match = q === '' || norm(item.getAttribute('data-search') || '').indexOf(q) !== -1;
                item.classList.toggle('is-hidden', !match);
                if (match) { anyVisible = true; }
            });
            sec.classList.toggle('is-hidden', !anyVisible);
        });
    });
})();
</script>
