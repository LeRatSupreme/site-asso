# Revue d'anonymisation — Lot A (dev-secu, brief A2)

- Revue effectuée le : 2026-10-27 14:20 UTC (16:20 Europe/Paris), par `dev-secu`
- Périmètre : `data/eval_t1/kaggle/` (destiné à sortir du VPS → Kaggle) + revue du script
  générateur `scripts/t1_export_eval.php` + contrôle du CLI Kaggle
- Référentiel : décision D3 validée (libellés produits seuls) — spec
  `docs/ANALYSE_T1_RAPPROCHEMENT_PRODUITS.md`
- Méthode : inventaire exhaustif, scans par motifs (commandes ci-dessous, relançables),
  contrôles croisés par script PHP (jointure, anti-fuite, cohérence parent↔export)
- **Ce rapport ne reproduit aucun fragment de prénom** (le libellé concerné est désigné
  ci-dessous par « le libellé décoration masqué »)

## 1. Inventaire des fichiers de `data/eval_t1/kaggle/`

| Fichier | Taille (o) | Lignes | Colonnes (documentées dans MANIFESTE.md) |
|---|---:|---:|---|
| `univers.csv` | 6 047 | 121 (120 identités + en-tête) | `libelle,forme_normalisee,famille,vivant,groupe` |
| `positifs.csv` | 1 744 | 51 (50 paires + en-tête) | `libelle_a,libelle_b` |
| `negatifs.csv` | 9 116 | 301 (300 paires + en-tête) | `libelle_a,libelle_b` |
| `durs_negatifs.csv` | 6 822 | 200 (199 paires + en-tête) | `libelle_a,libelle_b` |
| `split.csv` | 2 576 | 121 (120 affectations + en-tête) | `libelle,split` |
| `MANIFESTE.md` | 2 015 | 21 | documentation |

Aucun autre fichier (pas de `split.json`, pas de résiduels : conforme au MANIFESTE).
Nombre de champs constant par fichier (2, 2, 2, 2, 5) ; UTF-8 **sans BOM** ; aucun
caractère de contrôle ; aucune cellule commençant par `=`, `+`, `-`, `@` ni tabulation
(injection CSV).

## 2. Tableau fichier × contrôle × résultat

Motifs scannés sur l'intégralité des lignes de chaque fichier (les commandes sont en §4).

| Contrôle | univers.csv | positifs.csv | negatifs.csv | durs_negatifs.csv | split.csv | MANIFESTE.md |
|---|---|---|---|---|---|---|
| Fournisseurs (metro\|auchan\|carrefour\|lidl\|leclerc\|intermarch\|casino\|sumup…) | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ* |
| Prix/montants `[0-9]+[.,][0-9]{2}` | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ |
| Dates `20[0-9]{2}-[0-9]{2}` | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ |
| Emails / UUID / user_id / IP / token / secret / password | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ |
| Téléphone / SIRET / IBAN / code postal | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ |
| Prénom (fragment brut connu du cas décoration) | PASSÉ (masqué) | PASSÉ | PASSÉ (masqué) | PASSÉ | PASSÉ (masqué) | PASSÉ (doc uniquement) |
| Colonnes = MANIFESTE.md, aucune orpheline | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | n/a |
| UTF-8 sans BOM, structure CSV régulière | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ | PASSÉ |

\* MANIFESTE.md cite les noms de fournisseurs **en négatif**, comme documentation du
protocole d'exclusion (« aucun fournisseur… ») — faux positif assumé, aucune donnée de
transaction associée.

### Nature des nombres restants (tous PASSÉS comme libellés)

- `vivant` : flag 0/1 (donnée dérivée d'activité, sans montant ni volume).
- Nombres **intégrés aux libellés produits** : « Bowling ( 2 parties ) », « sucette 3
  sucettes », contenances commerciales « 25CL », « 33CL », « 50CL », « PET », « X6 »
  (ex. « COCA COLA BOITE FAT 33CL » dans `positifs.csv`) et clés métier
  `cristali5oclpet`, `oasispomcassframslim33cl` en colonne `groupe`. Ce sont des parties
  du nom commercial du produit, **pas** des colonnes quantité/prix/date de transaction —
  conforme D3.
- Les colonnes `ventes,achats,en_stock` existent UNIQUEMENT dans le fichier interne
  `data/eval_t1/univers.csv` (compteurs d'occurrences + somme de stock) — elles sont
  **absentes** de l'export Kaggle (voir §5, recommandation pour commit futur).

## 3. Contrôles croisés (scripts PHP, résultats bruts)

| Contrôle | Résultat |
|---|---|
| Masquage identique partout : le libellé décoration masqué apparaît 1× dans `univers.csv`, 1× dans `split.csv`, 6× dans `negatifs.csv` — **octet pour octet identique**, avec `forme_normalisee` et `groupe` recalculées depuis le libellé masqué (`decorationhalloween|prenom`) | PASSÉ |
| Fragment de prénom résiduel dans une colonne dérivée de l'export | PASSÉ (aucun : formes/groupes masquées recalculées) |
| Jointure interne export : les 300 `negatifs`, 199 `durs` et 120 `split` référencent tous des libellés présents dans `univers.csv` | PASSÉ (0 orphelin) |
| `positifs.csv` : les `libelle_a`/`libelle_b` sont des libellés historiques de fusions (documenté au MANIFESTE) ; 29/50 hors univers actuel — attendu par conception, aucun n'est une donnée sensible (libellés produits disparus) | PASSÉ (documenté) |
| Cohérence parent↔export : `positifs.csv` = projection exacte de `positifs_semantiques.csv` ; `durs_negatifs.csv` identique ; `negatifs.csv` = identique **sauf les 6 lignes masquées** (expected) ; `univers.csv` : 1 différence = la ligne masquée (expected) | PASSÉ |
| Anti-fuite du split **dans l'export** : 22 paires positives dont les 2 libellés sont dans `split.csv` → 0 paire à cheval train/test | PASSÉ |
| Paires résiduelles §1.3 et near-miss présentes dans l'export | PASSÉ (0 sur 13) |
| SHA256 des 12 fichiers (`sha256sum -c data/eval_t1/SHA256SUMS` depuis la racine du dépôt) | PASSÉ (12/12 OK) |

Note de transparence (non bloquant) : le libellé décoration masqué est affecté à son
split via le groupe réel (non masqué) du générateur ; la valeur train/test de
l'export (`split.csv`) est identique à celle du `split.json` interne — jointure préservée.

## 4. Commandes relançables par l'orchestrateur

```bash
cd /home/ubuntu/AEIC
# intégrité (12/12 attendu)
sha256sum -c data/eval_t1/SHA256SUMS
# fournisseurs — seul hit attendu : MANIFESTE.md ligne 8 (documentation d'exclusion)
grep -rniE "metro|auchan|carrefour|lidl|leclerc|intermarch|casino|sumup" data/eval_t1/kaggle/
# prix — aucun hit attendu
grep -rnoE "[0-9]+[.,][0-9]{2}" data/eval_t1/kaggle/
# dates — aucun hit attendu
grep -rnE "20[0-9]{2}-[0-9]{2}" data/eval_t1/kaggle/
# emails/UUID/user_id/IP/secrets — aucun hit attendu
grep -rniE "[a-z0-9._%+-]+@[a-z0-9.-]+|user_id|userid|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b(uuid|token|secret|password)\b" data/eval_t1/kaggle/
# fragment de prénom brut — aucun hit attendu (remplacer <frag> par le tronçon connu, sans le reproduire ici)
grep -rn "les creation" data/eval_t1/kaggle/   # seul hit attendu : MANIFESTE.md ligne 9 (règle documentée)
grep -c "PRENOM\]" data/eval_t1/kaggle/*.csv   # 1 / 0 / 6 / 0 / 1 selon le fichier
# encodage / structure
file data/eval_t1/kaggle/*.csv
# CLI Kaggle : jamais utilisé (preuves locales)
ls -la ~/.kaggle/                  # attendu : uniquement access_token, mtime 2026-10-27 13:28:46 UTC
stat -c '%y %n' ~/.kaggle/access_token
grep -c kaggle ~/.bash_history     # attendu : 0
ls ~/.cache | grep -i kaggle       # attendu : rien (pas de cache kagglehub)
```

Preuve **absolue** côté serveur (nécessite le réseau, INTERDITE pendant T1-P0 — à
exécuter par l'orchestrateur au moment de la transmission autorisée uniquement) :
`kaggle datasets list --mine` (aucun dataset « T1/eval_t1 » ne doit exister avant la
décision de transmission).

## 5. Revue du générateur `scripts/t1_export_eval.php`

| Point revu | Résultat |
|---|---|
| Requêtes : uniquement des `SELECT` (la seule instruction non-SELECT est le `SET time_zone` de session posé par `db()`) — aucune écriture base | PASSÉ |
| Colonnes lues : libellés/clés, `action`/`details`/`entity_id`/`id`/`created_at` d'audit, `stock` — **aucun prix, montant, fournisseur, user_id, IP, email jamais sélectionnés** (`sales` lu sur `product_key,description` uniquement) | PASSÉ |
| `created_at`/`id` gardés en interne (tri/priorité) mais **jamais exportés** (grep `id=[0-9]` sur tous les fichiers de sortie : 0 hit) | PASSÉ |
| Le stock lu (`product_stocks.stock`) n'alimente que le compteur `en_stock` du fichier interne — jamais l'export Kaggle | PASSÉ |
| Logs console : compteurs uniquement (aucun libellé, aucune donnée sensible) ; STDERR sans données | PASSÉ |
| Normalisation 100 % via `StockPublic::normalizeKey` / `AliasSuggester::normalizeKey` (aucun `strtolower` ad hoc — grep : 0 usage réel) — règle anti-régression AGENTS.md respectée | PASSÉ |
| Masquage appliqué à **tous** les fichiers de l'export (univers, positifs, negatifs, durs, split) avec recalcul des colonnes dérivées depuis le libellé masqué | PASSÉ |

## 6. Contrôle « CLI Kaggle jamais utilisé »

- `~/.kaggle/` ne contient qu'un `access_token` (38 o, mtime **13:28:46 UTC**), soit
  ~45 min **avant** la génération du Lot A (14:13:55 UTC) → dossier **intact**, aucune
  écriture par le CLI après sa configuration.
- Aucune commande `kaggle` dans `~/.bash_history` (0 occurrence) ; aucun cache
  `~/.cache/kagglehub` ; aucun process kaggle en cours.
- Statut déclaré et cohérent : **PRÊT, NON TRANSMIS**.

## 7. Recommandations (AVANT tout futur `git commit` de `data/eval_t1/`)

Le dossier parent `data/eval_t1/` (hors `kaggle/`) est actuellement **non suivi par git
et non ignoré** : un `git add -A` l'inclurait tel quel. Avant tout commit (à faire faire
à dev-database — aucune donnée modifiée par dev-secu) :

1. **Prénom tronqué en clair côté interne** : le libellé décoration apparaît non masqué
   dans `univers.csv` (l. 27), `negatifs.csv` (6 lignes), `positifs_casse.csv` (l. 82) et
   `split.json` (sa forme dérivée non masquée, qui contient le fragment tronqué). Le prénom est
   déjà tronqué en base (« … ») donc le risque est faible, mais D3 exige le masquage pour ce qui
   sort du VPS : appliquer le même masquage aux fichiers internes **ou** exclure ces fichiers
   du commit **ou** ajouter `data/eval_t1/` au `.gitignore` (ne committer que `kaggle/` +
   manifestes).
2. **Volumétrie interne** : `univers.csv` interne contient `ventes,achats,en_stock`
   (compteurs d'occurrences + somme de stock). Hors du strict « libellés seuls » de D3 :
   à retirer ou exclure du commit si l'exigence D3 est étendue au dépôt (un push est une
   sortie du VPS). Décision orchestrateur.
3. **Horodatage** : `manifeste_jeu.md` contient la date de génération (méta-donnée non
   personnelle) — non bloquant, mentionné par transparence.
4. **Limite du masquage** : `EvalDataset::masquerPrenom` cible le motif
   « … | les creation(s) de X » (seul cas connu, testé unitairement). Un futur libellé
   contenant un prénom sous un autre motif ne serait pas masqué automatiquement — à
   enrichir si de nouveaux cas apparaissent.
5. **Artefacts de paiement** : « Custom amount » / « Montant personnalisé » figurent
   comme identités de l'univers exporté (libellés, sans aucun montant) — acceptable,
   documenté au manifeste interne, à surveiller en D2.

## 8. Verdict final

**CONFORME** — l'export `data/eval_t1/kaggle/` ne contient que des libellés produits et
leurs dérivés déterministes (forme/famille/groupe/flag vivant/split), conformément à D3 :
aucun prix, montant, date, quantité ou volume de transaction, fournisseur, référence,
user_id, IP, email ni identité ; l'unique prénom connu est masqué de façon cohérente dans
tous les fichiers et toutes les colonnes dérivées ; la structure CSV est propre (UTF-8
sans BOM, pas d'injection, jointures intactes, 0 fuite train/test) ; le CLI Kaggle n'a
jamais été utilisé. Les cinq points ci-dessus sont des **recommandations préalables à un
futur commit du dossier interne** — aucune correction n'est requise pour la transmission
du seul dossier `kaggle/`.
