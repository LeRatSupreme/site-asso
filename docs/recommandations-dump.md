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
