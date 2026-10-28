# T4-P1 — Rapport d'optimisations vitesse (levier × effet mesuré × décision)

Toutes les mesures : démo 5 questions (`scripts/assistant.py --demo`), service réchauffé
(1 question de warmup après chaque redémarrage — le 1er passage après restart paie le
défaut de cache pages/modèle, ~+25 s sur Q1, non imputable aux drapeaux testés).
Baseline de référence : moyenne 42,9 s (cf. `baseline_timing.md`).

| Levier | Mesure | Effet | Décision |
|---|---|---|---|
| **Préfixe de prompt stable** (système + définitions octet-identiques) | journaux llama-server | Déjà en place : `definitions_outils()`/`message_systeme()` sont déterministes ; réutilisation KV mesurée (LCP `f_keep = 0.963`) | Rien à changer (vérifié) |
| **E1 — `--parallel 1`** (pont séquentiel → 1 slot suffit ?) | démo complète : 71,8 / 84,7 / 18,2 / 17,8 / 54,9 | Moyenne **49,5 s** : plus lent que la baseline (4 slots = meilleur recyclage LCP) | **Écarté** (revert) |
| **E2 — `-c 4096`** (contexte réduit ; max mesuré 1 514 tok) | démo complète : Q2→Q5 = 168,7 s vs 169,8 s baseline | **Neutre** en vitesse (le coût dépend du contexte UTILISÉ, pas du tampon) ; libère 450 Mo de RAM | **Testé puis revertu à 8192 en fin de tâche** : avec les 5 outils le pire cas (préfixe ~1 000 + 4 résultats × ~850 + générations ≈ 5 400 tok) ne tient plus — la condition du brief (« SI la totalité tient ») n'est plus remplie |
| **E3 — `-ub 1024`** (batch physique) | démo complète : 41,2 / 85,6 / 17,6 / 17,4 / 54,4 | Moyenne **43,2 s** ≈ baseline : aucun gain (préfill borné par la bande passante mémoire, pas par la taille de batch) | **Écarté** (revert) |
| **E4 — `-b 512 -ub 512`** (brief) | Q2 = 85,5 s / Q3 = 18,4 s (vs 85,6/17,6) | **Aucun effet** : `-ub` est déjà à 512 par défaut dans ce build (journal : `n_ubatch = 512`), `-b` ne fait que découper logiquement | **Écarté** (revert) — documenté, pas de code mort |
| **E5 — `--mlock`** | — | `--mlock` n'existe plus dans llama.cpp b11935 (renommé `--loading-mode mlock`, exigerait `LimitMEMLOCK` user-unit) ; et il serait **redondant** : le .service a déjà `MemorySwapMax=0` (le service ne peut pas swaper) | **Écarté** (aucun test de perf nécessaire) |
| **E6a — troncature pont produits_a_commander 8→5 produits, budget 2 800→1 900 caractères** | démo complète : 41,4 / **64,6** / 18,1 / 17,4 / 51,1 | Q2 : 85,6 → **64,6 s** (−21 s : ~350 tokens de préfill en moins) ; moyenne 38,5 s | **Gardé** (`TRONCATURE_PRODUITS_MAX = 5`) |
| **E6b — `TOP_LIMITE` 10→5 (endpoint ventes_periode)** | Q5 : 51,1 → **42,3 s** ; Q1 : 41,4 → 39,5 s | −5 produits dans le JSON du résultat ≈ −115 tokens de préfill ; le modèle n'énumérait de toute façon que ~5 produits (tests inchangés : 3 produits seedés attendus) | **Gardé** |
| **E6c — format compact doublons_en_attente (cap 10/section + `suggestions_non_listees`)** | question « doublons » : 94,6 → **78,0 s** | −1 200 caractères de résultat (28→20 paires listées max, total complet conservé) | **Gardé** |
| **Garde « appel d'outil en texte » (pont)** | 2 récupérations constatées sur les 2 dernières démos (Q3/Q4) | Échec probabiliste du tool-calling Qwen3-4B (réponse = `stock_produit{"nom": …}` sans appel réel) : le pont détecte et réinjecte une erreur, le modèle se corrige (+8 s quand il se déclenche, réponse sourcée garantie) | **Gardé** (indispensable avec 5 outils) |
| Timeout global 120 s / `--think` off / max 4 appels | inchangés | — | Conformes au brief |

## Verdict vitesse — honnête

- **À périmètre 3 outils (état E6)** : moyenne mesurée **35,4 s** vs 42,9 s baseline = **−17 %**.
- **À périmètre final 5 outils** : moyenne **43,3–43,9 s** (2 démos consécutives 5/5 OK).
  Les 2 nouveaux outils coûtent ~+290 tokens de préfixe (703 → ~1 000) + les récupérations
  aléatoires, ce qui restitue la moyenne au niveau baseline — tout en doublant la couverture fonctionnelle.
- **L'objectif ≤ 22 s n'est PAS atteint** et ne l'est pas atteignable par drapeaux/batch sur ce
  matériel : la génération plafonne à 6,4 tok/s et le préfill à 10–36 tok/s (4 vCPU Haswell,
  bande passante mémoire). Les questions courtes (stock) sont déjà à ~19–27 s ; les longues
  (réappro, ventes, doublons) paient ~40–60 s de préfill de résultat d'outils.
- Leviers restants NON actionnés (décision produit, pas technique — signalés au superviseur) :
  1. consigne « 5 lignes maximum » au lieu de 10 (≈ −8 s en moyenne sur la génération) ;
  2. réduction supplémentaire des charges utiles de résultats (fidelité moindre).

## État final du service llm-aeic (hors repo, documenté)

`~/.config/systemd/user/llm-aeic.service` : **restauré à l'identique de l'état d'origine**
(`-t 4 -c 8192 --cache-type-k q8_0 --cache-type-v q8_0 --jinja`). Sauvegardes datées conservées :
`llm-aeic.service.bak.20261027-par1`, `.bak.20261027-c4096`, `.bak.20261028-ub1024`,
`.bak.20261028-b512`, `.bak.20261028-mlock`. Innocuité vérifiée après chaque redémarrage :
`/health` OK, aremond.ovh 200, asso.aremond.ovh 200, MySQL vivant, swap stable (~1 Go, pages
anciennes hors services), service à 2,4 Go de RAM sans swap (MemorySwapMax=0).
