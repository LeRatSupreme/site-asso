# Manifeste du corpus T4-P2 — conversations tool-calling anonymisées

> Généré par `scripts/t4p2_corpus.py` (stdlib uniquement). Tout va dans
> `data/eval_t1/t4p2/` uniquement. Jeu figé `data/eval_t1/` intact.

## Reproductibilité

- **Graine : 42** (imposée). Tirages par conversation :
  `random.Random("42|<id>|essai<n>")`.
- Aucun horodatage dans les sorties du générateur, tri final par `id` :
  **deux exécutions → octets identiques** (preuve : `SHA256SUMS`, revérifié par
  `sha256sum -c SHA256SUMS` après régénération).
- Commande de régénération : `python3 scripts/t4p2_corpus.py`
- Preuves relançables :
  - `python3 scripts/t4p2_corpus.py --verifier-anonymisation`
  - `python3 scripts/t4p2_corpus.py --verifier-structure`

## Volumes

| | conversations |
|---|---:|
| total | **520** (cible 300-600) |
| train | 446 (85,8 %) |
| val | 74 (14,2 %) — 1 conversation sur 7, déterministe |

Moyenne 5,5 messages/conversation (min 3, max 9).

## Répartition par famille

| Famille | n | Contenu |
|---|---:|---|
| F1_outil_direct | 190 | 38 par outil : ventes_periode, stock_produit, produits_a_commander, marge_produit, doublons_en_attente ; registres tu/vous, reformulations, résolutions exact/normalise/alias |
| F2_suivi_memoire | 90 | 6 scénarios multi-tours : semaine → semaine d'avant, stock → autre produit, ventes → top produit (répondu depuis le contexte SANS nouvel appel), marge → mois, stock → quantité à commander, doublons → stock d'une clé de paire |
| F3_piege_injection | 55 | refus sec (fuite de prompt/jeton), bypass « réponds sans outil » REFUSÉ mais outil appelé quand même + source, injection incrustée dans une question métier (ignorée), changement de date/limite d'appels/DAN/falsification → refus |
| F4_hors_sujet | 60 | 50 refus propres (météo, cuisine, devoirs, politique…) + 10 questions capacités (« que sais-tu faire ? ») sans chiffre ni outil |
| F5_chiffres_source | 75 | question chiffrée → appel d'outil OBLIGATOIRE + source : dates calculées depuis « aujourd'hui » (hier, week-end, mois), 2 outils séquentiels (CA+stock, marge vs marge), nombre de doublons, coût du réappro (liste parfois tronquée) |
| F6_cas_limites | 50 | introuvable + candidats (stock et marge), introuvable sans candidat, date 400 → auto-correction en ISO, produits_a_commander tronqué (signalé), stock négatif (anomalie), quarantaine doublons, outil 500/429/timeout (échec honnête, jamais de chiffre inventé), période sans ventes, doublons indisponibles |

Couverture des outils (conversations concernées) : stock_produit 125,
ventes_periode 123, produits_a_commander 80, doublons_en_attente 80,
marge_produit 78.

## Format (une conversation par ligne JSONL)

```json
{"id": "t4p2-F1-stock-016", "famille": "F1_outil_direct", "outils": ["stock_produit"],
 "messages": [
   {"role": "system", "content": "<consigne production, date fictive 2027-03-16>"},
   {"role": "user", "content": "où en est le stock de Monster"},
   {"role": "assistant", "content": "", "tool_calls": [{"id": "appel-1", "type": "function",
     "function": {"name": "stock_produit", "arguments": "{\"nom\": \"Monster\"}"}}]},
   {"role": "tool", "tool_call_id": "appel-1", "content": "{…JSON exact de l'endpoint…}"},
   {"role": "assistant", "content": "Il te reste 20 unités de Monster … (source : stock_produit)"}],
 "tools": ["<les 5 définitions OpenAI, copie de definitions_outils()>"]}
```

- `arguments` : chaîne JSON (format OpenAI/Qwen), dates AAAA-MM-JJ.
- Résultats d'outils : JSON compact des endpoints, structures exactes des
  fixtures `tests/Integration/AssistantToolsTest.php`.
- Réponses attendues : français, ≤ 10 lignes, **source citée pour tout chiffre**,
  candidats proposés si `trouve=false`, troncature signalée, injections refusées.

## Anonymisation (contrainte vague 3 n°3)

- **Conservé** (validé D3) : libellés produits, catégories, paires de doublons,
  structures JSON, formulations des questions.
- **Synthétique (déterministe, graine)** : CA, montants, coûts, marges,
  pourcentages, stocks, quantités, transactions, ressemblances, horaires de
  comptage, toutes les dates (ancre fictive unique **2027-03-16**, périodes
  2027 uniquement).
- **Bannis** : 49 montants réels, 12 dates/datetimes réels (2026-xx), 8 entiers
  métier ≥ 50 extraits de `logs/assistant.jsonl`. Deux mécanismes :
  1. tirage avec rejet (`tirer_int`, filtre final anti-collision par
     conversation — un essai dérivé est retiré si une valeur bannie apparaît) ;
  2. preuve `--verifier-anonymisation` : scan des sorties (montants décimaux
     formes `.` et `,`, entiers nus hors contexte date/heure, dates 2026,
     mention « 2026- ») → **0 fuite**.
- Constantes STRUCTURELLES non bannies (packs 6/8/12/24, horizon 7 j, fenêtre
  14 j, quarantaine 48 h) : constantes du domaine, pas des données métier.
- Petits entiers (< 15) peuvent coïncider fortuitement avec des valeurs
  quelconques : ils sont tirés, jamais copiés, et non identifiants.

## Fichiers

| Fichier | Rôle |
|---|---|
| `corpus/train.jsonl`, `corpus/val.jsonl` | corpus d'entraînement (520 conversations) |
| `corpus/echantillon_validation.jsonl` | 20 conversations (1 sur 26, déterministe) pour revue manuelle |
| `kaggle_upload/{train,val}.jsonl` | copies pour upload Kaggle |
| `kaggle_upload/{train,val}.csv` | même contenu en CSV échappé (revue tableur) |
| `kaggle_upload/dataset-metadata.json` | dataset **privé** `adrienremond/aeic-assistant-corpus` |
| `SHA256SUMS` | empreintes des sorties (double-run) |

## Décision d'attente (vague 3)

L'**upload Kaggle n'est PAS fait** : les données anonymisées attendent la revue
**dev-secu** avant envoi (contrainte vague 3 n°2). L'entraînement complet est
différé (CPU-only) : ce corpus alimente le smoke run de pipeline puis le
fine-tuning quand le GPU Kaggle sera déverrouillé.
