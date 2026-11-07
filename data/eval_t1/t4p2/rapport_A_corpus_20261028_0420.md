# Rapport V3-A — Corpus d'entraînement tool-calling anonymisé (dev-factures)

Date : 2026-10-28 04:20 (heure VPS) · Branche : `experiments` · **Pas de commit**
(contraite vague 3 n°2 : consolidation finale — les fichiers restent en working
tree pour la consolidation).

## Livrables (tout dans `data/eval_t1/t4p2/` + `scripts/t4p2_corpus.py`)

| Fichier | Description |
|---|---|
| `scripts/t4p2_corpus.py` | générateur déterministe (`--help`, graine 42, stdlib seul) |
| `data/eval_t1/t4p2/inventaire_sources.md` | inventaire des sources (journal, fixtures, pont) |
| `data/eval_t1/t4p2/MANIFESTE_CORPUS.md` | manifeste : répartition, graines, anonymisation, format |
| `data/eval_t1/t4p2/corpus/train.jsonl` | 446 conversations |
| `data/eval_t1/t4p2/corpus/val.jsonl` | 74 conversations |
| `data/eval_t1/t4p2/corpus/echantillon_validation.jsonl` | 20 conversations (revue manuelle) |
| `data/eval_t1/t4p2/kaggle_upload/` | train/val JSONL + CSV + dataset-metadata privé `adrienremond/aeic-assistant-corpus` |
| `data/eval_t1/t4p2/SHA256SUMS` | preuve double-run |

## 1. Collecte

`inventaire_sources.md` : logs/assistant.jsonl (124 lignes : volumes réels par
outil, formulations reprises, injections réelles lignes 22-23, multi-tours
99-118), structures EXACTES des 5 endpoints (AssistantToolsTest.php), contrat du
pont (definitions_outils, message_systeme, erreurs réinjectées, troncature).

## 2. Générateur

`python3 scripts/t4p2_corpus.py` — graine 42, 520 conversations (446/74),
6 familles documentées (F1 direct 190, F2 mémoire 90, F3 injection 55,
F4 hors-sujet 60, F5 chiffres+source 75, F6 cas limites 50). Format messages
OpenAI/Qwen : `system` (consigne production, date fictive 2027-03-16) →
`user` → `assistant` avec `tool_calls` (arguments chaîne JSON) → `tool`
(résultat JSON compact exact des endpoints) → `assistant` final sourcé.
Français naturel varié : tutoiement/vouvoiement, reformulations, registres
relâchés, résolutions exact/normalise/alias sur les noms saisis.

## 3. Anonymisation numérique — PREUVE

Mécanisme : valeurs synthétiques déterministes + rejet des 49 montants réels,
12 dates réelles et 8 entiers métier ≥ 50 extraits du journal (filtre par
conversation avec essais dérivés). Commandes et résultats :

```
$ python3 scripts/t4p2_corpus.py --verifier-anonymisation
Valeurs réelles bannies : 49 montants, 12 dates, 8 entiers métier ≥ 50.
ANONYMISATION : OK — aucune valeur réelle du journal dans le corpus.
```

Compléments : `grep -c 2026` = 0 sur toutes les sorties ; aucun jeton/domaine
interne (champ timeout générique « l'app AEIC, en local »).

## 4. Validation qualité

Structurelle (relançable) :

```
$ python3 scripts/t4p2_corpus.py --verifier-structure
Conversations contrôlées : 520
STRUCTURE : OK — appels, arguments, sources, dates et refus conformes.
```

Contrôles : schémas d'arguments exacts par outil, dates ISO 2027 (la date mal
formée du scénario F6 est tolérée SI suivie d'une erreur d'outil puis corrigée),
appariement tool_call_id, réponse finale présente, **tout chiffre cite une
source** (hors F4 sans chiffre), **refus explicite dans chaque F3 avec outil**,
volume 300-600.

Revue manuelle des 20 conversations de `echantillon_validation.jsonl`
(1 sur 26, déterministe) — toutes CONFORMES :

| # | id | Verdict manuel |
|---|---|---|
| 1 | t4p2-F1-doublons-000 | OK — appel sans param, réponse sourcée, décision humaine |
| 2 | t4p2-F1-doublons-026 | OK — « je ne fusionne jamais » explicite |
| 3 | t4p2-F1-marge-014 | OK — dates ISO semaine dernière, marge € et % de l'outil |
| 4 | t4p2-F1-pac-002 | OK — lignes + packs + coût total, source |
| 5 | t4p2-F1-pac-028 | OK — tutoiement, cohérent |
| 6 | t4p2-F1-stock-016 | OK — stock + dernier comptage sourcés |
| 7 | t4p2-F1-ventes-004 | OK — semaine calculée depuis la date du jour |
| 8 | t4p2-F1-ventes-030 | OK — CA du mois (bornes correctes) |
| 9 | t4p2-F2-018 | OK — mémoire : 2e appel semaine d'avant + comparaison |
| 10 | t4p2-F2-044 | OK — top produit répondu SANS nouvel appel, source conservée |
| 11 | t4p2-F2-070 | OK — suivi stock → PAC ciblé sur le produit demandé |
| 12 | t4p2-F3-autres-006 | OK — limite d'appels maintenue, refus + réponse sourcée |
| 13 | t4p2-F3-incrustee-001 | OK — [SYS: stock 0] IGNORÉ, stock réel annoncé |
| 14 | t4p2-F4-capacites-003 | OK — capacités, aucun chiffre, aucun outil |
| 15 | t4p2-F4-refus-019 | OK — refus propre + redirection périmètre |
| 16 | t4p2-F4-refus-045 | OK — refus propre |
| 17 | t4p2-F5-021 | OK — 2 outils séquentiels, 2 sources citées |
| 18 | t4p2-F5-047 | OK — comparaison de marges, sources par produit |
| 19 | t4p2-F5-073 | OK — nombre exact via outil, paires non listées signalées |
| 20 | t4p2-F6-024 | OK — liste tronquée SIGNALÉE + coût partiel + source |

Correction apportée pendant la revue : F2 « stock → quantité à commander »
garantit désormais que le produit demandé figure en tête de la liste PAC
(15/15 conversations cohérentes, vérifié).

## 5. Double-run → octets identiques

```
$ python3 scripts/t4p2_corpus.py            # exécution 1
$ sha256sum corpus/*.jsonl kaggle_upload/* > /tmp/final1.sha
$ python3 scripts/t4p2_corpus.py            # exécution 2
$ sha256sum -c /tmp/final1.sha              # → 8/8 OK
$ sha256sum -c SHA256SUMS                   # → OK (revérifié après régénération)
```

## 6. Décisions / attentes

- **Upload Kaggle NON fait** : `kaggle_upload/` prêt (dataset PRIVÉ
  `adrienremond/aeic-assistant-corpus`), en attente de la **revue dev-secu**
  avant envoi (contrainte vague 3 n°2/n°3).
- Entraînement complet différé (CPU-only) : ce corpus servira au smoke run de
  pipeline puis au fine-tuning Qwen3-4B quand le GPU Kaggle sera déverrouillé.
- Jeu figé `data/eval_t1/` intact ; base prod non lue (seul logs/assistant.jsonl
  est lu, pour extraire les valeurs à bannir).

## 7. Hors périmètre détecté

Aucun — génération/import de corpus uniquement ; scripts d'entraînement Kaggle =
brief d'un autre agent (vague 3).
