# Revue d'anonymisation P2 — kaggle_upload/ (dev-secu, brief P2-SEC)

- Revue effectuée le : 2026-10-27 15:50 UTC, par `dev-secu`
- Périmètre : `data/eval_t1/p2/kaggle_upload/` (destiné à sortir du VPS → Kaggle, entraînement
  P2) — mêmes grille et méthode que la revue A2 (`data/eval_t1/kaggle/REVUE_ANONYMISATION.md`)
- Référentiel : décision **D3** (libellés produits seuls) + contraintes
  `/tmp/p2_briefs/00_CONTRAINTES.md` (n°2 : relecture dev-secu AVANT tout envoi Kaggle)
- Méthode : inventaire exhaustif, scans par motifs, **preuve anti-fuite indépendante** du
  générateur (script reconstituant les identités TEST depuis le jeu figé, normalisation via le
  port fidèle `scripts/t1_similarity.py`), relecture I/O du générateur, contrôle « CLI Kaggle
  jamais utilisé »
- **Ce rapport ne reproduit aucun fragment de prénom** (le cas décoration est désigné par
  « le libellé décoration halloween » — univers.csv l.27)

## 1. Inventaire de `data/eval_t1/p2/kaggle_upload/`

| Fichier | Taille (o) | Lignes | Colonnes (documentées dans manifeste_p2.md) |
|---|---:|---:|---|
| `train_pairs.csv` | 57 608 | 1 784 (1 783 + en-tête) | `texte_variante,cible` ✓ |
| `val_pairs.csv` | 6 235 | 179 (178 + en-tête) | `texte_variante,cible` ✓ |
| `dataset-metadata.json` | 143 | 7 | `title,id,isPrivate,licenses` ✓ (manifeste §« Dossier Kaggle ») |

- **Exactement 3 fichiers** : pas de `split.json`, pas de résiduels, aucun fichier caché
  (`find -name ".*"` : 0) — contrôle n°4 du brief : **PASSÉ**.
- Aucune colonne non documentée ; 2 champs/ligne partout (moyenne 2,00) ; 0 cellule vide ;
  0 doublon de ligne exacte ; UTF-8 **sans BOM** (3 premiers octets `74 65 78`) ; 0 caractère
  de contrôle ; 0 CR/CRLF.
- Copies **identiques octet/octet** aux parents `data/eval_t1/p2/{train,val}_pairs.csv`
  (comparaison CSV ligne à ligne + `sha256sum -c data/eval_t1/p2/SHA256SUMS` : 5/5 OK).
- `dataset-metadata.json` : JSON valide, `isPrivate: true` (privé), licence `OTHER`,
  `title: aeic-t1-p2-pairs` — conforme au manifeste. Jeu figé intact
  (`sha256sum -c data/eval_t1/SHA256SUMS` : 12/12 OK).
- Cohérence manifeste vérifiée : 1 783/178 lignes ✓ ; **26 cibles distinctes** dans train et
  val, cibles val ⊆ cibles train (26/26) ✓ ; libellés exacts des variantes disjoints
  train/val (0 intersection) ✓.

## 2. Tableau contrôle × résultat × commande relançable

Toutes les commandes depuis `/home/ubuntu/AEIC/data/eval_t1/p2/kaggle_upload/` sauf mention.

| # | Contrôle | Résultat | Commande relançable |
|---|---|---|---|
| 1 | Fournisseurs (metro\|auchan\|carrefour\|lidl\|leclerc\|intermarché\|casino\|sumup) | **PASSÉ** (0 hit, exit 1) | `grep -rniE "metro\|auchan\|carrefour\|lidl\|leclerc\|intermarch\|casino\|sumup" .` |
| 2 | Prix/montants `[0-9]+[.,][0-9]{2}` | **PASSÉ** (0 hit) | `grep -rnoE "[0-9]+[.,][0-9]{2}" .` |
| 3 | Dates (`20YY-MM`, `JJ/MM/20YY`, `AAAAMMJJ`) | **PASSÉ** (0 hit) | `grep -rnE "20[0-9]{2}-[0-9]{2}\|[0-9]{2}/[0-9]{2}/20[0-9]{2}\|\b[0-9]{4}[0-9]{2}[0-9]{2}\b" .` |
| 4 | Emails / UUID / user_id / token / secret / password / api_key | **PASSÉ** (0 hit) | `grep -rniE "[a-z0-9._%+-]+@[a-z0-9.-]+\|user_id\|userid\|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\|\b(uuid\|token\|secret\|password\|passwd\|api_?key)\b" .` |
| 5 | Téléphone / SIRET / IBAN / code postal | **PASSÉ** (0 hit) | `grep -rnE "(\+33\|0033)[0-9 \.]{8,}\|\b[0-9]{3} ?[0-9]{3} ?[0-9]{3}\b|\bFR[0-9A-Z]{12,}\b|\b75[0-9]{3}\b" .` |
| 6 | Prénom : masque `[PRENOM]` cohérent partout | **PASSÉ** (0 occurrence : le libellé décoration halloween n'est membre d'aucune paire sémantique — 0 hit `decoration` dans `positifs_semantiques.csv` — donc absent de tout fichier p2) | `grep -rniE "decoration\|PRENOM" data/eval_t1/p2/` → 0 ; `grep -rn "les creation" data/eval_t1/p2/` → 0 |
| 7 | Injection CSV (cellule début `=`,`+`,`-`,`@`, tab) | **PASSÉ avec 1 exception documentée** (voir §3) : `train_pairs.csv` l.481, cellule `+ boissons (hors boissons energisante)` | `awk -F',' 'NR>1 && ($1 ~ /^[=+@]/ \|\| $1 ~ /^-[^0-9]/ \|\| $2 ~ /^[=+@-][^0-9]/)' *.csv` |
| 8 | Nombres présents = parties de libellés commerciaux (33CL, X6, 5OCL PET, « Sucette 1 sucette ») — pas de colonne quantité/prix/date | **PASSÉ** (mêmes clés métier que l'univers A2, aucune colonne transactionnelle : 2 colonnes uniquement) | inspection §1 |
| 9 | Colonnes = manifeste_p2.md, aucune orpheline | **PASSÉ** | §1 |
| 10 | Structure : UTF-8 sans BOM, 0 ctrl, 0 CR, 0 vide, 0 doublon | **PASSÉ** | `head -c3 train_pairs.csv \| od -An -tx1` ; `grep -cP "[\x00-\x08\x0b\x0c\x0e-\x1f]" *.csv` ; `awk -F',' 'NF!=2\|\|$1==""\|\|$2==""' *.csv` |
| 11 | Pas de `split.json` ni résiduels dans kaggle_upload/ | **PASSÉ** (3 fichiers exactement, 0 fichier caché) | `find data/eval_t1/p2/kaggle_upload/ -type f \| sort` |
| 12 | Intégrité : SHA256SUMS p2 5/5 + jeu figé 12/12 | **PASSÉ** | `sha256sum -c data/eval_t1/p2/SHA256SUMS && sha256sum -c data/eval_t1/SHA256SUMS` |

### Anti-triche TEST→TRAIN (contrôle n°3 du brief — deux preuves)

| Preuve | Résultat | Commande |
|---|---|---|
| `--verifier` du générateur (relancé par dev-secu) | **OK exit 0** — sortie : `paires TEST du jeu : 20 · formes interdites (normalisées) : 62` ; `libellés résiduels §1.3 : 22 · formes : 34` ; `lignes vérifiées : train 1783 + val 178` ; exception documentée `'Lion', 'M&Ms', 'Sucette 1 sucette', 'gaufre'` (paires TRAIN qualifiées du jeu figé) ; `résultat : OK`. Fichiers inchangés après exécution (SHA 5/5) | `~/AEIC/.venv-t1/bin/python3 scripts/t1_augment.py --verifier` |
| **Indépendante** (dev-secu) : reconstitution des paires TEST depuis `split.json` (mapping) + `positifs_semantiques.csv` + `univers.csv`, formes interdites = union des 40 libellés des identités TEST et des **34 libellés des 20 paires TEST** (34 = chiffre du manifeste, retrouvé), en exact + normalisé (`normalize_key`/`stock_public_normalize` du port fidèle) → **137 formes** ; comparaison de chaque cellule (variante ET cible) de train_pairs.csv et val_pairs.csv | **0 fuite exacte, 0 fuite normalisée** dans les 2 fichiers ; copies kaggle_upload == parents ; VERDICT : OK | script archivé §5 (principe ci-dessous dans le rapport ; exécution venv obligatoire) |

Aucun libellé des paires TEST du jeu figé n'est présent dans
`kaggle_upload/train_pairs.csv` (ni exact, ni normalisé, ni en colonne `cible`) —
**exigence du brief : PASSÉ**, et de plus l'absence tient aussi pour `val_pairs.csv`
et contre l'ensemble élargi des 40 identités TEST de l'univers.

## 3. Seule exception (MINEUR, documentée) — cellule débutant par `+`

- `train_pairs.csv` **ligne 481** : `texte_variante` = `+ boissons (hors boissons energisante)`
  → `cible` = `Gaufre + boissons (hors boissons energisante)`.
- Provenance **authentique** : paire positive TRAIN qualifiée du jeu figé
  (`positifs_semantiques.csv` l.14 : « Gauffre + boissons… » ↔ « Gaufre + boissons… » ;
  `univers.csv` l.40/43 ; reprise telle quelle dans l'export A2 `kaggle/positifs.csv` l.14).
  La variante est la paire amputée de son premier mot (« Gaufre ») — typologie faute
  documentée au manifeste. Le `+` initial fait **partie du libellé réel**, pas d'un payload.
- Risque réel évalué : en tableur, la cellule serait interprétée comme formule
  `+ boissons (hors…)` → erreur `#NAME?` immédiate — **inerte** (aucune fonction, aucun `|`,
  aucun lien externe, aucune donnée exfiltrable). Destination effective : ingestion ML sur
  Kaggle (dataset **privé**), pas ouverture tableur.
- Décision : **non bloquant pour l'envoi** (D3 = anonymisation : respectée). Neutraliser par
  apostrophe de tête dégraderait le texte d'entraînement (l'entrée du modèle est la chaîne
  brute) — recommandé de **ne pas modifier**.

## 4. Revue du générateur `scripts/t1_augment.py` + contrôle « CLI Kaggle jamais utilisé »

| Point revu | Résultat |
|---|---|
| Écritures : UNIQUEMENT `data/eval_t1/p2/` (train/val CSV, manifeste, SHA256SUMS, `kaggle_upload/`) ; jamais le jeu figé (SHA 12/12 avant/après `--verifier`) | PASSÉ |
| Réseau / sous-processus : aucun `requests`/`urllib`/`socket`/`subprocess`/`os.system` | PASSÉ |
| Base de données : aucun accès (lecture seule des CSV/JSON figés) — contrainte n°3 respectée | PASSÉ |
| Secrets : aucun secret/token/identifiant embarqué (uniquement des noms de chemins « kaggle_upload ») ; pas de lecture d'environnement | PASSÉ |
| Normalisation 100 % via le port fidèle `t1_similarity.py` (import, aucun `strtolower` ad hoc) — règle anti-régression AGENTS.md | PASSÉ |
| CLI Kaggle : `~/.kaggle/` = uniquement `access_token` (38 o, mtime 13:28:46 UTC, **antérieur** à la génération p2 15:41-15:44) ; `grep -c kaggle ~/.bash_history` = 0 ; aucun cache kagglehub ; aucun process kaggle | PASSÉ — statut « PRÊT, NON TRANSMIS » cohérent |

## 5. Script de la preuve indépendante

Archivé hors dépôt : `/tmp/p2_secu_check.py` (lecture seule, aucune écriture dans le dépôt).
Relance : `~/AEIC/.venv-t1/bin/python3 /tmp/p2_secu_check.py` (venv requis pour
`t1_similarity` → numpy). Sortie clé : `paires TEST reconstituées : 20 (attendu 20)`,
`libellés distincts de ces paires : 34 (attendu 34)`, `formes interdites : 137`,
`fuites exactes : 0`, `fuites normalisées : 0`, `VERDICT_INDEPENDANT : OK`.

## 6. Verdict final

**CONFORME** — `data/eval_t1/p2/kaggle_upload/` ne contient que des libellés produits
(paires `texte_variante,cible`) et leurs dérivés, conformément à D3 : aucun prix, montant,
date, quantité de transaction, fournisseur, référence, user_id, IP, email, UUID ni identité ;
le seul prénom connu du jeu est structurellement absent (le libellé décoration n'est membre
d'aucune paire d'entraînement) ; l'injection CSV se réduit à une cellule `+` authentique et
inerte (§3, non bloquant) ; l'anti-fuite TEST→TRAIN est prouvée deux fois (vérifieur du
générateur + preuve indépendante à 137 formes interdites : 0 fuite) ; le dossier ne contient
ni `split.json` ni résiduels ; le CLI Kaggle n'a jamais été utilisé.

**Autorisation dev-secu pour l'envoi Kaggle : ACCORDÉE** (sous réserve des deux points
opérationnels ci-dessous, à la charge de l'orchestrateur, qui ne relèvent pas de
l'anonymisation).

### Recommandations (non bloquantes, aucune correction exigée de dev-factures)

1. **Propriétaire Kaggle** : `dataset-metadata.json` porte `id: aeic/aeic-t1-p2-pairs` —
   confirmer le slug propriétaire au moment de l'envoi (aucun `~/.kaggle/kaggle.json`,
   auth `access_token`) ; garder `isPrivate: true` (vérifié).
2. **Ne pas ouvrir les CSV en tableur** en l'état (§3) ; ne surtout pas « neutraliser » la
   cellule `+` dans le fichier d'entraînement (données authentiques du jeu figé).
3. Rappel A2 §7 toujours valable : les fichiers **internes** `data/eval_t1/p2/` (hors
   `kaggle_upload/`) ne sortent pas — au futur commit, respecter la décision git prise pour
   `data/eval_t1/` (rien n'a été commité ici, contrainte P2 n°1).
