# T4-P1 — Baseline de vitesse de l'assistant (AVANT toute optimisation)

Mesuré le 27/10/2026 23:44 UTC — `~/AEIC/.venv-assistant/bin/python3 scripts/assistant.py --demo`
(service llm-aeic actif depuis 20:59, cache KV chaud, 3 outils P0, réflexion off).

| # | Question | Outil appelé | Tour 1 LLM | Tour 2 LLM | Appel endpoint | Durée totale | Statut |
|---|----------|--------------|-----------:|-----------:|---------------:|-------------:|--------|
| 1 | Comment ça a marché cette semaine ? | ventes_periode(2026-10-20→27) | 7 s (47 tok) | 37 s (96 tok) | 90 ms | **44,9 s** | OK |
| 2 | Qu'est-ce qu'il faut que je rachète ? | produits_a_commander | 3 s (19 tok) | 77 s (113 tok) | 283 ms | **81,5 s** | OK |
| 3 | Il me reste combien de Bueno ? | stock_produit(nom=Bueno) | 4 s (23 tok) | 14 s (45 tok) | 262 ms | **18,9 s** | OK |
| 4 | Combien me reste-t-il de Coca Cola ? | stock_produit(nom=Coca Cola) | 4 s (24 tok) | 13 s (49 tok) | 230 ms | **18,5 s** | OK |
| 5 | Ventes entre le 20 et le 26 octobre 2026 ? | ventes_periode(2026-10-20→26) | 8,5 s (47 tok) | 42 s (130 tok) | 87 ms | **50,9 s** | OK |

**Moyenne P0 ≈ 42,9 s** (5/5 sessions OK, aucune réponse sans source). Rapport brut : `logs/assistant_demo_20261027_2344.md`.

## Longueurs de contexte mesurées (usage du serveur, ajout T4-P1)

Via `usage.prompt_tokens` (instrumentation pont T4-P1) :

- Préfixe système + définitions des 3 outils + question ≈ **703 tokens** (stable d'une question à l'autre).
- Conversation max observée (Q2, résultat produits_a_commander 8 produits ≈ 1 748 caractères) : **1 514 tokens**.

## Décomposition du temps (journaux llama-server + pont)

- Génération : **~156 ms/token** (6,4 tok/s) — 4 vCPU Haswell, bande passante mémoire limitée.
- Préfill (prompt eval) : **~36 tok/s** sur prompt à froid (micro-benchmark 141→1 264 tokens),
  mais **~10–16 tok/s** en trafic réel quand le contexte KV est long (taxe d'attention sur le cache).
- Le coût dominant des questions lentes (Q2, Q5) = préfill du résultat d'outil du tour 2
  (Q2 : ~800 tokens neufs ≈ 60 s de préfill à 13 tok/s), pas la génération.
- Réutilisation du cache KV du préfixe : CONFIRMÉE côté serveur
  (« selected slot by LCP similarity, f_keep = 0.963 ») — le préfixe
  système+définitions est octet-identique d'une requête à l'autre (date du jour exceptée).
- FlashAttention : déjà actif (« enabling flash_attn since it is required for quantized V cache »).
