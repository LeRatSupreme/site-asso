# Recommandations — dump prod avant/après

- **Date** : 2026-10-21 17:33 UTC
- **Commit déployé** : `e9f172c` (« Reco : integration (carte analytics + kiosque, historique, decisions, seuils) + docs + script rapport »)
- **Écritures effectuées** : exclusivement (1) les 2 migrations prévues (`database/migrations/2026_recommendations.sql` — table `recommendations` ; `2026_recommendations_settings.sql` — 7 réglages `reco_%`) et (2) les lignes `recommendations` créées naturellement par une visite de la page kiosque admin analytics. Le moteur ne modifie **jamais** prix ni stock : le site PROPOSE, un humain DÉCIDE.
- **Méthode** : script `scripts/reco_report.php` (lecture seule) exécuté sur le VPS avant puis après migration + visite.

## AVANT — idées générées sur les données actuelles

(sortie de `php scripts/reco_report.php --statuts` sur le code déployé `e9f172c`, avant application des migrations — la table `recommendations` n'existait pas encore, d'où « Idées proposées : — aucune »)

```
=== Idées de recommandations — AEIC (générées le 2026-10-21 19:31 Paris) ===
Créneau analysé : 2026-09-22 → 2026-10-21 (30 j) · 22 jours ouverts · historique insuffisant : non · période creuse : oui (facteur 0.8) · paniers : 1410 · candidats rejetés par les garde-fous : 0

1) R11 | Renseigner le coût de crepe | gain 0,00–0,00–0,00 €/sem | confiance haute | effort 1 | score 0,00 | produits : crepe
2) R11 | Renseigner le coût de Oasis (ice tea) | gain 0,00–0,00–0,00 €/sem | confiance haute | effort 1 | score 0,00 | produits : Oasis (ice tea)
3) R11 | Renseigner le coût de Haribo | gain 0,00–0,00–0,00 €/sem | confiance haute | effort 1 | score 0,00 | produits : Haribo
4) R1 | Écouler le stock dormant de Nutella B-ready | gain 9,91–9,91–9,91 €/sem | confiance moyenne | effort 2 | score 3,71 | produits : Nutella B-ready
5) R1 | Écouler le stock dormant de Lipton Ice Tea | gain 7,74–7,74–7,74 €/sem | confiance moyenne | effort 2 | score 2,90 | produits : Lipton Ice Tea

== Idées proposées (en attente de décision) ==
— aucune.

== Applications (historique + effets, max 20) ==
— aucune.
```

## APRÈS — idées persistées en base

(même script après migrations + une visite de la page kiosque admin analytics ; les 5 idées de la première génération sont persistées en statut `proposee`, le moteur génère donc les candidats suivants)

```
=== Idées de recommandations — AEIC (générées le 2026-10-21 19:33 Paris) ===
Créneau analysé : 2026-09-22 → 2026-10-21 (30 j) · 22 jours ouverts · historique insuffisant : non · période creuse : oui (facteur 0.8) · paniers : 1410 · candidats rejetés par les garde-fous : 0

1) R1 | Écouler le stock dormant de eau | gain 6,33–6,33–6,33 €/sem | confiance moyenne | effort 2 | score 2,37 | produits : eau
2) R1 | Écouler le stock dormant de mars | gain 5,15–5,15–5,15 €/sem | confiance moyenne | effort 2 | score 1,93 | produits : mars
3) R1 | Écouler le stock dormant de M&Ms | gain 2,94–2,94–2,94 €/sem | confiance moyenne | effort 2 | score 1,10 | produits : M&Ms
4) R1 | Écouler le stock dormant de Coca zero | gain 2,84–2,84–2,84 €/sem | confiance moyenne | effort 2 | score 1,06 | produits : Coca zero
5) R1 | Écouler le stock dormant de Capri-sun | gain 2,48–2,48–2,48 €/sem | confiance moyenne | effort 2 | score 0,93 | produits : Capri-sun

== Idées proposées (en attente de décision) ==
- [R1] Écouler le stock dormant de Lipton Ice Tea (proposee le 2026-10-21 17:33:23)
- [R1] Écouler le stock dormant de Nutella B-ready (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de Oasis (ice tea) (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de Haribo (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de crepe (proposee le 2026-10-21 17:33:23)

== Applications (historique + effets, max 20) ==
— aucune.
```

## Chiffres à l'appui

- **Stock dormant (R1)** : Nutella B-ready — gain estimé **9,91 €/semaine** (score 3,71) et Lipton Ice Tea — **7,74 €/semaine** (score 2,90) ; les candidats suivants (eau 6,33, mars 5,15, M&Ms 2,94 €/sem…) confirment un vivier de réglage fin du stock.
- **Coûts manquants (R11)** : **3 produits** sans coût fiable (crepe, Oasis (ice tea), Haribo) — les gains sont affichés 0,00 €/sem jusqu'à saisie du coût ; c'est l'action la plus rapide (effort 1) pour fiabiliser les futures idées.
- **Périmètre analysé** : 30 jours glissants (22 jours ouverts), 1 410 paniers, période creuse détectée (facteur 0,8), **0 candidat rejeté** par les garde-fous.
- **Persistance** : 5 lignes `recommendations` créées par la seule visite de la page (statut `proposee`), 0 application, 0 rejet — en attente de décision humaine.

## APRÈS correctif — fourchettes différenciées

- **Date** : 2026-10-21 19:43 Paris
- **Commit déployé** : `eca0bd3` (« Reco : correctif extraction prevision produits (fourchettes R1 differenciees, R2 evaluable) »)
- **Cause du correctif** : `RecoData::dataset()` cherchait la prévision produits dans une liste indexée comme un tableau par clé → prévisions toujours nulles → fourchettes R1 plates (9,91–9,91–9,91) et R2 muette. La prévision est désormais réellement consommée.

(sortie de `php scripts/reco_report.php --statuts` sur le code déployé `eca0bd3` — les 5 idées R1/R11 du 17:33 étant toujours en statut `proposee`, leurs fingerprints sont exclus de la nouvelle génération)

```
=== Idées de recommandations — AEIC (générées le 2026-10-21 19:43 Paris) ===
Créneau analysé : 2026-09-22 → 2026-10-21 (30 j) · 22 jours ouverts · historique insuffisant : non · période creuse : oui (facteur 0.8) · paniers : 1410 · candidats rejetés par les garde-fous : 0

1) R3 | Tester une hausse de prix sur Bueno_white | gain 0,00–5,76–9,91 €/sem | confiance moyenne | effort 2 | score 2,16 | produits : Bueno_white
2) R2 | Rupture imminente : Gauffre | gain 1,73–1,81–1,90 €/sem | confiance haute | effort 1 | score 1,81 | produits : Gauffre
3) R3 | Tester une hausse de prix sur Red bull ice | gain 0,00–4,26–8,46 €/sem | confiance moyenne | effort 2 | score 1,60 | produits : Red bull ice
4) R3 | Tester une hausse de prix sur Bueno | gain 0,00–3,92–6,85 €/sem | confiance moyenne | effort 2 | score 1,47 | produits : Bueno
5) R3 | Tester une hausse de prix sur Redbull Peach | gain 0,00–2,32–4,60 €/sem | confiance moyenne | effort 2 | score 0,87 | produits : Redbull Peach

== Idées proposées (en attente de décision) ==
- [R1] Écouler le stock dormant de Lipton Ice Tea (proposee le 2026-10-21 17:33:23)
- [R1] Écouler le stock dormant de Nutella B-ready (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de Oasis (ice tea) (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de Haribo (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de crepe (proposee le 2026-10-21 17:33:23)

== Applications (historique + effets, max 20) ==
— aucune.
```

- **R1 différencié** : évaluées en direct (règle pure, mêmes données prod), les fourchettes R1 ne sont plus plates — Lipton Ice Tea **3,09–3,31–3,52 €/sem** (72 u. = 14,0 sem de stock à 5,15 u./sem prévues, intervalle [4,90 ; 5,40]), Nutella B-ready **3,58–3,87–4,16 €/sem**, mars **1,03–1,22–1,41 €/sem** ; aucune hypothèse « Fourchette non différenciée ».
- **R2 réapparue** : Gauffre **1,73–1,81–1,90 €/sem** (confiance haute, effort 1) — muette avant correctif (prévision nulle refusée par `prev > 0`) ; les quatre R3 sont aussi à bornes réelles (ex. Bueno_white 0,00–5,76–9,91 €/sem).
- **Persistance** : toujours **5 idées** en attente (2 R1 + 3 R11, générées le 2026-10-21 17:33 avant correctif), 0 application, 0 rejet ; page kiosque admin analytics **HTTP 200** avec « Idées du moment ».

## Itération 2 — deux blocs, consignes datées (max 3 idées)

- **Date** : 2026-10-21 22:38 Paris
- **Commit déployé** : `e60f1fb` (« Reco : integration deux blocs (liens fiche, seuils max_idees/max_corrections) + diag --diag + doc iteration 2 »)
- **Écritures effectuées** : migration `database/migrations/2026_recommendations_settings_v2.sql` (vérifiée en base : `reco_max_idees` = 3, `reco_max_corrections` = 5) + lignes `recommendations` créées par une visite de la page kiosque admin analytics (HTTP 200) : nouvelle fingerprint R1 « mars » (version datée) + **R5 et R6 écrites à 20:38**. Aucune modification de prix ni de stock.
- **Carte en prod** : deux blocs « 🔧 À corriger » et « 🚀 Idées pour vendre plus » présents, consigne datée « Du 22/10/2026 au 23/10/2026 » (R1 jeudi→vendredi), **aucune occurrence de R3** ; 3 idées affichées (R1, R5, R6) + 3 corrections (R2).

### Pourquoi R5/R6/R7 ne se déclenchaient pas (diagnostic du 2026-10-21)

- **1410 paniers** analysés (30 j), dont **964 (68,4 %) à une seule ligne**.
- **R5 (duos)** bloquée par le seuil **support ≥ 2 %** : le max réel est **1,8 %** (Bueno_white + eau, 26 paniers communs). Les paires utiles démarrent à **0,9–1,1 %** de support avec des lifts nets : **Bueno_white + Nutella B-ready 3,13**, Bueno_white + Redbull Abricot 2,14, M&Ms + Red bull ice 2,02, eau + cafe 1,54. À l'inverse, Bueno_white + eau (lift **0,89**) est une anti-association : les deux produits se remplacent.
- **R6 (seuil cadeau)** bloquée par le seuil **85 %** de paniers à 1 ligne (réel : 68,4 %). Les cadeaux candidats existent pourtant : **Sucette 0,07 €**, cafe 0,11 €, Madeleine 0,11 €.
- **R7 (happy hour)** passait **toutes** ses portes (16 heures creuses, 3 pics, écart 26,6 points, 53 produits à coût utilisable) mais était **évincée du cap 5** par 5 candidats R3 (micro-optimisations de prix) qui occupaient toute la place.

### Assouplissements retenus (itération 2)

- **R5** : lift **> 1,0** (anti-association : lift ≤ 1,0 rejetée), support **≥ 0,5 %**, confiance **≥ 3 %**.
- **R6** : porte abaissée à **> 50 %** de paniers à 1 ligne (au lieu de 85 %).
- **R7** : remise plafonnée, plancher **min 5 %** (repère 15 %).
- **R3 écartée** : plus émise du tout (meta `ecartees`) ; son ancienne ligne `proposee` du 17:47 reste en base.
- Caps séparés : **3 idées** max + **5 corrections** max (R11 prioritaire) ; message d'honnêteté si 0 ou 1 idée.

### Sortie CLI (étape 4)

(sortie intégrale de `php scripts/reco_report.php --statuts` sur le code déployé `e60f1fb`)

```
=== Idées de recommandations — AEIC (générées le 2026-10-21 22:38 Paris) ===
Créneau analysé : 2026-09-22 → 2026-10-21 (30 j) · 22 jours ouverts · historique insuffisant : non · période creuse : oui (facteur 0.8) · paniers : 1410 · candidats rejetés par les garde-fous : 0

1) R4 | Mettre en avant Twix (forte marge, sous-vendu) | gain 0,13–0,26–0,39 €/sem | confiance moyenne | effort 1 | score 0,19 | produits : Twix
2) R4 | Mettre en avant cafe (forte marge, sous-vendu) | gain 0,11–0,23–0,34 €/sem | confiance moyenne | effort 1 | score 0,17 | produits : cafe
3) R4 | Mettre en avant Gauffre choco (forte marge, sous-vendu) | gain 0,11–0,22–0,33 €/sem | confiance moyenne | effort 1 | score 0,16 | produits : Gauffre choco

== Idées proposées (en attente de décision) ==
- [R5] Duo promo : Bueno_white + Redbull Abricot (proposee le 2026-10-21 20:38:08)
- [R2] Rupture imminente : crepe (proposee le 2026-10-21 20:38:08)
- [R2] Rupture imminente : Redbull red (proposee le 2026-10-21 20:38:08)
- [R2] Rupture imminente : Mister Freeze (proposee le 2026-10-21 20:38:08)
- [R1] Offre fin de semaine : mars (proposee le 2026-10-21 20:38:08)
- [R6] Seuil cadeau pour enrichir les paniers (proposee le 2026-10-21 20:38:08)
- [R2] Rupture imminente : Gauffre (proposee le 2026-10-21 17:47:51)
- [R3] Tester une hausse de prix sur Redbull Peach (proposee le 2026-10-21 17:47:51)
- [R1] Écouler le stock dormant de Lipton Ice Tea (proposee le 2026-10-21 17:33:23)
- [R1] Écouler le stock dormant de Nutella B-ready (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de Oasis (ice tea) (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de Haribo (proposee le 2026-10-21 17:33:23)
- [R11] Renseigner le coût de crepe (proposee le 2026-10-21 17:33:23)

== Applications (historique + effets, max 20) ==
— aucune.
```

Constats complémentaires (`--diag`, capture du 22:39) :

- **R6 conforme** à la variante attendue : part1 **68,4 %** (> 50 %), cadeau **Sucette 0,07 €** retenu (candidates cafe 0,11 €, Madeleine 0,11 €).
- **Divergence R5** : la paire retenue et persistée est Bueno_white + **Redbull Abricot** (lift 2,14) et non le duo attendu avec **Nutella B-ready** (lift 3,13) — mêmes support (0,9 %) et confiance (6,2 %), 12 paniers communs pour les deux paires ; consigne datée non (fenêtre R1 uniquement), gain estimé 1,93–5,78 €/sem.
- **R7 absente de l'affichage** : toutes ses portes internes sont PASSÉES et la relance moteur par règle la retient (1 idée), mais le **cap global de 3 idées** la laisse hors top 3 au profit de trois R4 (scores 0,19 / 0,17 / 0,16) — non affichée, donc non persistée. Porte bloquante : cap `reco_max_idees` + score R7 trop bas. Rien corrigé (hors périmètre).
- **Anomalie cosmétique** : les libellés de seuils affichés par `--diag` sont obsolètes (« lift>1,2 · support≥2 % », « seuil 85,0 % ») alors que le moteur déployé a bien retenu R5 à support 0,9 % et R6 à 68,4 %.

### Chiffres à l'appui

- **Duo + marge** : « Proposez le duo Bueno_white + Redbull Abricot à **2,25 € au lieu de 2,50 € (-10 %)** » — marge duo **0,37 €** contre 0,39 € en vente séparée ; association présente dans 0,9 % des paniers (lift 2,14), à afficher en caisse pendant 2 semaines.
- **Seuil cadeau + coût** : **68,4 %** des paniers n'ont qu'une ligne → « Au-delà de **2,00 €** d'achat : offrez un(e) Sucette (coût **0,07 €**) », gain estimé 3,66–10,97 €/sem.
- **Happy hour (R7, portes passées, hors cap)** : consigne attendue produit + remise + heures — 53 produits à coût utilisable, remise 15 % (plancher 5 %) sur les heures creuses **19h/21h/22h (0,05 % des parts)** vs pics 16h (26,07 %), 14h (23,94 %), 10h (26,65 %).
- **Corrections à part** : 3 corrections affichées sur la carte (3× R2 rupture : crepe, Redbull red, Mister Freeze) ; en base, 13 `proposee` dont 4 R2 et **3 R11 « Renseigner le coût »** — les anciennes lignes du premier run restent, R3 n'est plus générée (1 seule ligne historique du 17:47).
