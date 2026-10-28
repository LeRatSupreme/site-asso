# T4-P1-D — Validation finale vague 1 (dev-tests, 28/10/2026 00:31–01:50 UTC)

Suite complète, E2E chronométré (service actif), couverture des 5 outils,
résilience, santé VPS. Aucune modification de code (validation seule).

## 1. Suite de tests (aeic_test, garde anti-prod active)

- `php -l` : OK sur les 6 fichiers PHP touchés par la vague
  (AdminAssistantController, AssistantToolsController, vue assistant.php,
  IntegrationTestCase, AssistantToolsTest, AdminAssistantMemoryTest).
- Suite complète : **1 380 tests / 7 611 assertions / 0 échec**
  (l'échec `KiosquePagesRenduTest` signalé par A ne s'est pas reproduit —
  date/ordre aléatoire, conforme au rapport B).
- Filtres : `AdminAssistantMemoryTest` + `AssistantToolsTest` = **23 tests /
  173 assertions OK** (mémoire 2 questions liées, reset=1, isolation 2 sessions ;
  20 cas endpoints dont les 2 nouveaux outils).

## 2. E2E démo chronométrée — avant/après (service actif, cache chaud, 1 warmup)

| # | Question | Baseline 27/10 (3 outils) | Après 28/10 (5 outils) | Écart |
|---|----------|--------------------------:|-----------------------:|------:|
| 1 | Comment ça a marché cette semaine ? | 44,9 s | 41,9 s | −3,0 s |
| 2 | Qu'est-ce qu'il faut que je rachète ? | 81,5 s | 71,6 s | −9,9 s |
| 3 | Il me reste combien de Bueno ? | 18,9 s | 26,0 s | +7,1 s |
| 4 | Combien me reste-t-il de Coca Cola ? | 18,5 s | 21,5 s | +3,0 s |
| 5 | Ventes entre le 20 et le 26 octobre 2026 ? | 50,9 s | 50,4 s | −0,5 s |
|   | **Moyenne** | **42,9 s** | **42,3 s** | **−0,6 s (−1,4 %)** |

- 5/5 OK, **une source par réponse chiffrée** (aucun avertissement R4).
- Rapport brut : `logs/assistant_demo_20261028_0131.md`.
- Lecture honnête : le périmètre est passé de 3 à 5 outils entre les deux
  mesures (préfixe mesuré 703 → ~1 060 tokens via `prompt_tokens`). À iso-
  3-outils les optimisations donnent 35,4 s (−17 %, cf. rapport_optimisations.md) ;
  à périmètre final 5 outils, ce run (42,3 s) confirme la fourchette 43,3–43,9 s
  d'A : les 2 nouveaux outils restituent la moyenne au niveau baseline en
  doublant la couverture fonctionnelle. Q3 +7,1 s = variance de longueur de
  génération (2 tours comme les autres, garde « outil en texte » non déclenchée).

### Questions de suivi avec mémoire (contexte 1 échange)

| Suivi | Contexte mémorisé | Outil appelé | Durée | Constat |
|---|---|---|---:|---|
| A « Et la semaine précédente ? » | ventes 21→28 | ventes_periode(21→28) | 49,6 s | Sourcé ✅ — fenêtre = convention outil « semaine = 7 derniers jours » (cf. SANS contexte : 21→27), pas la semaine calendaire N-1 |
| A2 « Et la semaine dernière ? » | idem | ventes_periode(21→28) | 50,6 s | Idem A |
| B « Quelle est la marge de ce produit… ? » | stock Coca Cola | marge_produit(nom=Coca Cola, 12→18) | 47,4 s | Mémoire produit résolue ✅ (« ce produit » → Coca Cola), sourcé |

Coût mémoire mesuré : ~+100 tokens de préfill par échange mémorisé
(1 060 → 1 160 tok), cohérent avec l'estimation B (+5-10 s pour 1-2 échanges).

## 3. Couverture des 5 outils — réponses croisées

Chiffres annoncés par l'assistant VS endpoint appelé en direct (GET lecture
seule, mêmes paramètres) VS base (SELECT lecture seule) :

| Outil | Question | Chiffres assistant | Endpoint direct | Base |
|---|---|---|---|---|
| ventes_periode | Q1 démo (21→28) | 289,15 € / 179 tx / 268 u ; Monster Blanche 37,8 € | 289.15 / 179 / 268 / 37.8 ✅ | 289.15 / 268 / 179 ✅ |
| ventes_periode | Q5 démo (20→26) | 379,05 € / 232 tx / 344 u | 379.05 / 232 / 344 ✅ | 379.05 / 344 / 232 ✅ |
| stock_produit | Q3/Q4 démo | Bueno 24 (comptage 13/10) ; Coca Cola 17 (comptage 19/10) | 24 / 17 + mêmes horodatages ✅ | (via StockBook = point d'assemblage unique) |
| produits_a_commander | Q2 démo | Monster Blanche 24 u 32,11 € ; Redbull 24 u 26,35 € ; Peach 31,20 € ; Pepsi Zero 10,49 € | identiques ✅ | idem endpoint |
| marge_produit | suivi B | 6,29 € / 33,1 % / coût moyen 0,669 € / Boissons | 6.29 / 33.1 / 0.669 / Boissons ✅ | idem endpoint |
| doublons_en_attente | question directe | 27 doublons ; « Minute Maid »/« Minute Maid Pomme » (89) ; « décision humaine » | total 27, 1re paire 89 ✅ | idem endpoint |

Aucun écart : 7/7 réponses conformes aux endpoints, 2 croisements SQL exacts.
Toutes les réponses chiffrées portent leur source (outil + paramètres).

## 4. Résilience

| Scénario | Preuve | Résultat |
|---|---|---|
| llama stoppé → pont | `systemctl --user stop llm-aeic` + question CLI | Message gracieux « Le service de l'assistant (llama-server) ne répond pas… relance : systemctl --user start llm-aeic », échec immédiat (0,0 s), code sortie 3 ✅ |
| llama stoppé → page | harnais ad-hoc (runner aeic_test, SANS dry-run) : POST /admin/assistant/ask | HTTP 503 + `{"ok":false,"error":"L'assistant local est éteint (service llm-aeic arrêté). Relance-le…, puis repose la question."}` ✅ |
| llama stoppé → rendu page | GET /admin/assistant | HTTP 200, page Assistant rendue, bouton « Nouvelle conversation » présent, AUCUN jeton dans la page ✅ |
| Redémarrage | `systemctl --user start llm-aeic` | /health `status:ok` ; 1re question à froid 73,8 s (cache froid documenté), 2e 20,9 s (régime chaud retrouvé) ✅ |
| reset conversation | `AdminAssistantMemoryTest::test_reset_purge_la_memoire` | Vert (session purgée, question suivante sans contexte) ✅ |
| 2 sessions isolées | `test_deux_sessions_ne_partagent_pas_leur_memoire` | Vert (aucune fuite d'historique) ✅ |

## 5. Santé VPS pendant l'E2E

- `free` : swap 974 Mo avant démo → 974 Mo après → 968 Mo après restart
  (inchangé ; détenteur = anciennes pages mariadbd/pi, llama-server 0 Mo de
  swap — MemorySwapMax=0, RSS ~3,0 Go). Aucune croissance.
- aremond.ovh → 200 ; asso.aremond.ovh → 200 ; MySQL vivant (`SELECT 1` + 1 380 tests).
- Jeton : jamais affiché, absent de la page, du stdout et du journal.

## Verdict

- Suite : tout vert. E2E : 5/5 sourcés + 2 suivis mémoire fonctionnels.
- 5/5 outils couverts et conformes aux endpoints + base.
- Résilience : messages gracieux à tous les étages, service sain après restart.
- À connaître (pas bloquant) : « la semaine dernière » suit la convention outil
  « 7 derniers jours », pas la semaine calendaire N-1 — mention utile en démo.
