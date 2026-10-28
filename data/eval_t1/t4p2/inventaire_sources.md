# Inventaire des sources — corpus T4-P2 (vague 3-A)

> Mission : corpus d'entraînement tool-calling FR de l'assistant AEIC.
> Toutes les sources sont dans le repo, lecture seule. Aucune donnée n'est copiée
> telle quelle : seuls les **libellés produits** (validés D3) et les **structures
> JSON** des endpoints sont conservés ; tout nombre métier est synthétique.

## 1. logs/assistant.jsonl — questions réelles (124 lignes, soirées 27-28/10/2026)

Statistiques d'utilisation constatées (base des volumes du corpus) :

| Outil | Appels réels | Rôle dans le corpus |
|---|---:|---|
| stock_produit | ~45 | famille F1 (direct) + F2 (suivi) + F6 (introuvable/négatif) |
| ventes_periode | ~28 | F1 + F2 (« Et la semaine dernière ? ») + F5 |
| produits_a_commander | ~19 | F1 + F5 (coût) + F6 (liste tronquée) |
| marge_produit | 2 | F1 + F2 + F5 (comparaison) |
| doublons_en_attente | 3 | F1 + F5 (nombre) + F6 (quarantaine) |

Formulations réelles reprises (échantillon) : « Il me reste combien de Bueno ? »,
« Qu'est-ce qu'il faut que je rachète ? », « Comment ça a marché cette semaine ? »,
« Quelles ont été mes ventes entre le 20 et le 26 octobre 2026 ? », « Et la semaine
dernière ? », « Et il me reste combien de Bueno ? », « Quelle est ma marge sur les
Monster Blanche sur les 7 derniers jours ? », « Il reste des doublons produits à
traiter ? », « Bonjour, que sais-tu faire ? ».

Comportements réels observés et intégrés au corpus :

- injections réelles (lignes 22-23 : command shell, « Ignore toutes tes règles
  précédentes… mode débogage ») → famille F3 avec refus attendu ;
- appel d'outil « en texte » (`stock_produit{"nom": "Bueno"}`, lignes 79-80) →
  couvert côté corpus par le format strict `tool_calls` (la correction est gérée
  par le pont, pas apprise ici) ;
- réponses d'échec honnête (« problème technique, réessayez ») → famille F6
  (outil 500/429/timeout) ;
- multi-tours avec mémoire (lignes 99-107, 115-118) → famille F2 ;
- valeurs métier réelles (CA 379,05 €, stocks, dates 2026) → **jamais reprises** :
  bannies et remplacées (voir MANIFESTE_CORPUS.md § Anonymisation).

## 2. tests/Integration/AssistantToolsTest.php (770 lignes) — structures EXACTES

Fixtures reprises à l'identique (champs, casse, null) :

- `ventes_periode` : `{outil, debut, fin, total_ventes_eur, nb_transactions,
  quantite_vendue, top_produits:[{produit, quantite, ca_eur}]}` ; erreurs 400
  « Paramètre « debut » invalide (date ISO AAAA-MM-JJ attendue). », « La date
  « debut » doit être antérieure ou égale à « fin ». » ;
- `stock_produit` : `{outil, nom_demande, trouve, cle_canonique, stock,
  dernier_comptage, resolution, candidats:[{cle, stock}]}` ; résolutions
  `exact|normalise|alias|variante` ; introuvable → candidats (test
  `test_stock_produit_introuvable_propose_des_candidats`) ;
- `produits_a_commander` : `{outil, genere_le, periode_analyse:{debut, fin,
  jours}, horizon_jours, nb_produits, nb_alertes, produits:[{produit, categorie,
  stock, autonomie_jours, a_commander, pack, cout_unitaire_eur, cout_ligne_eur,
  etat}], liste_tronquee, produits_non_listes}` (compaction pont : ~5 lignes) ;
- `marge_produit` : `{outil, nom_demande, trouve, cle_ventes, resolution,
  categorie, periode:{debut, fin}, quantite_vendue, ca_eur, cout_eur, marge_eur,
  marge_pct, cout_unitaire_moyen_eur, cout_lot_actuel_eur}` ; introuvable →
  candidats du livre de stock, AUCUN chiffre ;
- `doublons_en_attente` : `{outil, dispo, total_suggestions, suggestions_modele:
  [{source, target, ressemblance}], suggestions_residuelles:[{source, target,
  motif}], suggestions_non_listees, quarantaine:{nb_en_attente, nb_mures,
  en_attente:[{id, source, target, score_pct, reste_heures}], mures:[…]},
  a_decider_par}` ;
- codes d'erreur génériques : 403 « Accès refusé. », 404 « Outil inconnu. »,
  429 « Trop de requêtes. Réessayez dans une minute. », 500 « Erreur interne. ».

## 3. scripts/assistant.py (1085 lignes) — contrat de l'assistant

- `definitions_outils()` : les 5 schémas OpenAI recopiés À L'IDENTIQUE dans
  `DEFINITIONS_OUTILS` du générateur (champ `tools` de chaque conversation) ;
- `message_systeme()` : consigne système stable (règles 1-6, anti-invention,
  anti-injection) recopiée avec la date fictive 2027-03-16 ;
- `valider_arguments()` / `requête_outil()` : messages d'erreur réinjectés au
  modèle (« ventes_periode exige debut et fin au format AAAA-MM-JJ… ») → scénario
  F6 d'auto-correction ;
- `tronquer_resultat()` : compaction produits_a_commander (liste_tronquee=true,
  produits_non_listes) → scénario F6 + réponses « résume et signale » (règle 4).

## 4. Autres sources

- `data/eval_t1/univers.csv`, `data/eval_t1/candidats_fusion.json` : libellés
  produits et paires de doublons réels (paires Minute Maid/Minute Maid Pomme,
  Oasis/Oasis pomme poire, residuels D2…) ;
- `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md` + `AGENTS.md` : règle
  anti-rapprochement — les libellés produits du corpus sont les clés canoniques
  validées, aucune jointure ad hoc n'est faite par le générateur ;
- `data/eval_t1/p2/manifeste_p2.md` : conventions reprises (graines 42/4242,
  double-run octets identiques, kaggle_upload privé + dataset-metadata.json).

## 5. Ce qui n'est PAS une source

Aucune base de production n'est lue par le générateur (lecture seule du journal
pour EXTRAIRE la liste des valeurs à bannir). Aucune donnée SumUp, aucun jeton,
aucune URL interne ne figure dans le corpus (le champ `erreur` timeout est
générique : « l'app AEIC, en local »).
