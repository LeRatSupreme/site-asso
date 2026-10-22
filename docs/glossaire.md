# Glossaire AEIC — un seul mot par idée

Vocabulaire officiel de l'interface (admin, kiosques, pages bénévoles).
Règle : **un nom par concept**, en français courant, en vouvoiement.
Les synonymes listés sont **interdits dans les libellés** (titres, boutons,
colonnes, messages) : si un écran en utilise un, c'est un défaut.

| Concept | Terme officiel | Synonymes interdits | Où c'est visible |
|---|---|---|---|
| Lieu de vente de l'association | **la cafétéria** | kiosque (pour le lieu), snack, bar | Catalogue produits, commandes élèves, carte de l'accueil public |
| Accès téléphone par lien secret à jeton | **le kiosque** | lien magique, accès direct, téléphone (seul) | Liens « Accès téléphone », pages `/kiosque/*`, page Kiosques (Réglages) |
| Action de compter ce qui existe physiquement | **l'inventaire** (faire un comptage) | stock (pour l'action), relevé, état des lieux | Pages Inventaire, comptage à l'aveugle, kiosque comptage |
| Quantité possédée, disponible à la vente | **le stock** | inventaire (pour la quantité), réserve, réserve physique | Colonne « Stock restant », fiches produit, liste de courses |
| Argent qui rentre réellement (ventes encaissées) | **l'argent encaissé** | recettes (seul), cash, trésorerie encaissée | Cartes KPI du tableau de bord, récapitulatifs du jour |
| Total des ventes sur une période (indicateur de gestion) | **le chiffre d'affaires (CA)** | turnover, ventes totales, revenue | Analyses, bilan annuel — réservé aux sections « Détails techniques » et aux tableaux d'analyse |
| Ce qui reste après le coût des produits vendus | **le bénéfice** | profit brut, gain, plus-value | Lignes « Bénéfice », récapitulatifs du jour (kiosque admin) |
| Écart entre prix de vente et coût, en pourcentage | **la marge** | taux de marque, coefficient, rentabilité | Analyses, fiches produit (« Par produit ») |
| Sortie d'argent sans lien avec un achat de produits | **la dépense** | charge, frais (seuls), sortie de caisse | Onglet Dépenses, livre comptable |
| Entrée de produits achetés pour revendre | **l'achat** | commande fournisseur, appro (seul), réassort | Onglet Achats, listes de lots, coûts de revient |
| Produits perdus, cassés, périmés, offerts | **la perte** | casse, déstockage, gâchis | Onglet Pertes, calcul du stock théorique |
| Quantité relevée pendant un inventaire | **le comptage** | relevé, saisie physique (seul), dénombrement | Grille de comptage, comptage à l'aveugle, comptage de caisse |
| Différence entre comptage et stock théorique | **l'écart** | différence, dispo, delta, drift | Colonne « Dernier écart », comptage de caisse, historique d'inventaire |
| Estimation des ventes à venir | **la prévision** | prédiction, projection, forecast | Carte Analytics, réapprovisionnement (liste de courses) |
| Fourchette basse/haute de la prévision | **la fourchette** | intervalle de confiance (dans les libellés), bornes min/max, quantiles | Bande affichée sur les graphiques, colonnes des tableaux de réappro |
| Niveau de certitude de la prévision, exprimé en mots | **la confiance** | fiabilité (en %), score, probabilité | Cartes Idées (« confiance élevée / moyenne / faible ») |
| Rapprochement des ventes SumUp avec les produits | **l'import SumUp** | import CSV (seul), synchro, ingestion | Page « Importer un rapport SumUp », bloc « À faire » |
| Table de correspondance libellé SumUp → produit | **le mapping des libellés** (page « Libellés ») | alias (dans les libellés), rattachement automatique, traduction | Onglet Libellés de l'espace Produits, ajout d'une appellation |
| Trace horodatée de qui a fait quoi | **le journal d'audit** | log, trace (seule), historique système | Page Journal d'audit (Accueil > niveau 2), mentions de traçabilité |
| Liste de ce qu'il faut racheter | **la liste de courses** | shopping list, réappro (pour cette vue), caddie | Pages « Liste de courses » (admin et kiosque) |
| Quantité à acheter pour couvrir la demande | **à commander** | besoin net, réassort, réappro (pour cette colonne) | Réapprovisionnement, liste de courses |
| Quantité vendue par jour en moyenne | **la consommation par jour** | vitesse d'écoulement, throughput, conso moyenne (seule) | Réapprovisionnement (« Conso / jour ») |
| Durée avant rupture de stock | **l'autonomie** | days of cover, couverture, jours restants | Réapprovisionnement (« Autonomie ») |
| Produit jamais réapprovisionné (quantité non suivie) | **stock infini** | produit fantôme, illimité, non suivi | Bouton « stock ∞ », réapprovisionnement |
| Produit retiré temporairement de la vente | **en pause** | désactivé, discontinué, archivé | Inventaire (« Mettre en pause »), rétablissement en un clic |
| Somme d'argent attendue en caisse | **le solde théorique de caisse** | fond de caisse (pour le solde), cash attendu | Page Caisses, comptage de caisse |
| Argent liquide réellement compté | **le comptage de caisse** | levée de caisse, clôture | Comptage de caisse, page Caisses |

## Détails techniques

Termes statistiques autorisés **uniquement** dans les sections repliées
« Détails techniques » des pages Analytics et Idées (jamais dans un titre,
un KPI ou un message principal) :

| Terme technique (interdit en libellé courant) | Terme officiel en section « Détails techniques » |
|---|---|
| WAPE (modèle ou backtest) | **écart moyen de prévision** (en % du CA) |
| baseline naïve / baseline B2 | **référence naïve** (comparaison « répéter le passé ») |
| quantile / dispersion r / borne quasi-Poisson | **bornes de fourchette** et **dispersion** |
| couverture d'intervalle | **part de prévisions dans la fourchette** |
| z-score / atypie | **jour atypique** (pic ou creux) |
| product_key / transaction_ref / sumup_ref | **clé produit**, **référence de transaction**, **référence SumUp** (libellés de colonnes techniques uniquement) |

## Règle de rédaction

- Vouvoiement systématique (« Enregistrez », « Votre comptage »).
- Une idée = un mot : le même objet porte le même nom sur toutes les pages
  (admin, kiosque, téléphone), y compris dans les messages d'erreur.
- Aucun code interne (identifiants, noms de colonnes brutes, codes de
  règles) hors section « Détails techniques ».
