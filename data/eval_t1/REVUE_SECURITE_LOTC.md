# Revue sécurité — Lot C (écran « candidats de fusion » + boucle P3)

- **Revue** : dev-secu (brief C2, mission T1-P0) — 27/10/2026
- **Périmètre** : `POST /admin/compta/inventaire/t1/decide` (`AdminStockController::t1Decide`), carte « Suggestions de rapprochement » (`views/admin/compta/inventory.php`, assemblage `app/core/Compta/T1/T1Suggestions.php`), route `app/config/routes.php:437`, `AuditLog::entityIdsForAction()`.
- **Verdict : CONFORME — aucun correctif requis.** Détail des contrôles et preuves relançables ci-dessous. Toutes les preuves dynamiques ont été exécutées sur la base de TEST `aeic_test` (docker 127.0.0.1:3307) via un serveur `php -S` dédié (port 8099) ; la base de production `aeic` n'a jamais été contactée.

## 1. Tableau contrôle × statut × preuve

| # | Contrôle | Statut | Preuve |
|---|----------|--------|--------|
| 1 | **Entrées de `t1Decide`** : whitelist stricte, bornes, comparaison BRUTE + `canMerge`, aucune entrée dans un chemin/requête | ✅ CONFORME | Code : `AdminStockController.php:1253-1372` — `decision` ∈ {fusion, ignorer} et `origine` ∈ {modele, residuel} par `in_array(..., true)` ; `source`/`target` trim, non vides, `mb_strlen ≤ 255` ; refus si `source === target` **en brut** ; `ProductKeyMerge::canMerge` (formes normalisées via `AliasSuggester::normalizeKey` — même repliement que `StockPublic::normalizeKey`, règle AGENTS respectée, zéro `strtolower` ad hoc dans le diff) ; `score` optionnel `is_numeric` → `float`, hors [0,1] → `null`. Chemins `data/eval_t1/*` codés en dur (`AdminStockController.php:710-711`), jamais alimentés par le POST. Dynamique : POST 300 caractères → refus « Clé source et clé cible requises. », 0 écriture ; `source===target` → refus ; `PEPSI` vs `Pepsi` (casse) → refus `canMerge`, 0 écriture ; `score=42` → clampé `null` dans l'audit. |
| 2 | **CSRF** : token dans le formulaire + vérification middleware (parité `/merge`) | ✅ CONFORME | Vue : `csrf_field()` dans les 4 formulaires (2 par paire). Routeur : `app/core/Router.php:135` — tout POST hors `/kiosque/` exige `Csrf::checkRequest()` (hash_equals, `app/core/Csrf.php:21-53`), AVANT l'entrée du contrôleur — exactement comme `/admin/compta/inventaire/merge`. Dynamique : root authentifié + POST **sans** `_csrf` → `403 « jeton CSRF invalide »`, 0 écriture ; POST **avec** token → traité (302). |
| 3 | **Autorisation** : `guardSystemOrPage('inventory')` identique aux voisins | ✅ CONFORME | `AdminStockController.php:1255` = même garde que `mergeKeys()` (:1095) et `inventory()` (:615) ; défense en profondeur routeur (`/admin/*` : invité → login, élève → 403). Dynamique : élève authentifié **avec CSRF valide** → GET inventaire = 403, POST t1/decide = 403 « Accès refusé » ; BENEVOL (bureau sans page « inventory ») **avec CSRF valide** → 302 `/admin` + flash « Accès refusé : vous n'avez pas les droits requis », 0 écriture ; SUPERADMIN → 200. |
| 4 | **XSS** : libellés produits (semi-libres) systématiquement `e()` — vue + flash + % + sections | ✅ CONFORME | Vue (`inventory.php:497-570`) : `source`, `target`, `motif`, `data-confirm`, `value` des champs cachés → tous `e()` (ENT_QUOTES\|ENT_HTML5) ; `ressemblance` cast `(int)`. Flash : rendu via `views/partials/flash_messages.php` (`e()` systématique) — et les flashs de `t1Decide` ne contiennent AUCUN libellé produit (« Fusion effectuée : 1 stock additionné. », messages génériques). Modale `data-confirm` : `confirm.js` insère par `textContent` (jamais `innerHTML` pour le message). Dynamique (libellés hostiles `<script>alert('T1XSS-A')</script>` et `<img src=x onerror=…>` injectés via livrables temporaires + clés vivantes en base test) : page inventaire → **0 occurrence brute**, occurrences échappées `&lt;script&gt;alert(&apos;T1XSS-A&apos;)&lt;/script&gt;` (affichage, attribut `data-confirm`, champ caché) ; historique `/admin/audit` (rendu des `details` stockés) → 0 occurrence brute, échappée — préexistant et sûr (`views/admin/audit.php:81-95`, tout `e()`). |
| 5 | **Fuite d'information** : messages génériques utilisateur, détails en logs | ✅ CONFORME | Parité mot pour mot avec `mergeKeys()` : « Fusion refusée : la demande est invalide. » / « Fusion impossible : une erreur inattendue est survenue. L'incident a été journalisé. » — motif réel (`$e->getMessage()`) uniquement dans `Logger::warning/error`, jamais affiché. Aucune trace de stack, d'ID interne ou de SQL dans les flashs. |
| 6 | **Périmètre** : git propre (rien commité), rien hors ~/AEIC, cron/www intacts, `data/` non servi | ✅ CONFORME | `git log -1` = `4572a5f` (inchangé), `git status` = modifications des lots non commités uniquement, **zéro commit/push** (contrainte §1). Cron : aucune écriture (`crontab -l` lecture seule, aucune entrée T1). `/var/www` : non touché. Apache : `asso.conf` → `DocumentRoot /home/ubuntu/AEIC/public` → `data/`, `.venv-t1/` HORS webroot ; `grep -rn "eval_t1\|data/" app/config/routes.php` → aucune route exposant `data/` (seules routes `…/data/{token}` = kiosque préexistant) ; serveur de preuve monté avec `-t public` exclusivement. |
| 7 | **Audit** : `t1.pair_feedback` ne fuient que libellés + score + décision | ✅ CONFORME | `AdminStockController.php:1330-1342` — `details` = `{a, b, score, decision, origine}` exactement ; dynamique : `{"a":"Pepsi","b":"Pepsi sans sucre","score":null,"decision":"ignorer","origine":"modele"}` — **pas d'IP dans details** (l'IP va dans la colonne dédiée `ip_address` préexistante, comportement paritaire de TOUTES les écritures d'audit du projet via `AuditLog::log`) ; aucun montant, aucune donnée comptable. `entity_id` = sha1 40 hex symétrique. |

## 2. Commandes relançables (base de TEST uniquement)

```bash
cd /home/ubuntu/AEIC
bash scripts/test-db-up.sh                       # docker aeic_test sur 127.0.0.1:3307

# ── Seed (base TEST) ─────────────────────────────────────────────────────
HASH=$(php -r "echo password_hash('Password123456', PASSWORD_BCRYPT);")
SECRET=$(php -r "require 'vendor/autoload.php'; echo App\Core\Security\Totp::generateSecret();")
mysql -h 127.0.0.1 -P 3307 -u aeic -paeic aeic_test <<SQL
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE two_factor; TRUNCATE TABLE audit_logs; TRUNCATE TABLE users;
TRUNCATE TABLE sales; TRUNCATE TABLE import_batches; TRUNCATE TABLE purchases;
TRUNCATE TABLE losses; TRUNCATE TABLE product_aliases; TRUNCATE TABLE product_costs;
TRUNCATE TABLE inventory_counts; TRUNCATE TABLE product_stocks; TRUNCATE TABLE promotions;
TRUNCATE TABLE inventory_tablet_lines; TRUNCATE TABLE product_discontinued;
TRUNCATE TABLE product_infinite; TRUNCATE TABLE product_zero_since; TRUNCATE TABLE product_packs;
TRUNCATE TABLE shopping_checks; TRUNCATE TABLE cafeteria_order_items;
TRUNCATE TABLE cafeteria_orders; TRUNCATE TABLE products;
SET FOREIGN_KEY_CHECKS=1;
INSERT INTO users (id,prenom,nom,email,password,role,is_active,email_verified_at) VALUES
 ('u_secu_root','Secu','Root','secu-root@exemple.fr','$HASH','SUPERADMIN',1,NOW()),
 ('u_secu_bene','Secu','Bene','secu-benevol@exemple.fr','$HASH','BENEVOL',1,NOW()),
 ('u_secu_eleve','Secu','Eleve','secu-eleve@exemple.fr','$HASH','ELEVE',1,NOW());
INSERT INTO two_factor (user_id,secret,enabled,enabled_at) VALUES ('u_secu_root','$SECRET',1,NOW());
INSERT INTO sales (id,transaction_ref,sold_at,payment_method,quantity,description,product_key,price_ttc)
 VALUES ('s_secu_1','SECU1','2026-10-20 10:00:00','CARTE',1,'Pepsi','Pepsi',2.00);
INSERT INTO product_stocks (product_key,stock) VALUES ('Pepsi',5),('Pepsi sans sucre',7);
UPDATE settings SET value='0' WHERE `key`='maintenance_mode';  -- artefact du dump, à remettre à 1 après
SQL

# ── Serveur dédié pointé sur aeic_test (jamais la prod) ──────────────────
DB_HOST=127.0.0.1:3307 DB_NAME=aeic_test DB_USER=aeic DB_PASS=aeic \
  php -S 127.0.0.1:8099 -t public public/index.php \
  -d session.save_path=$PWD/cache/t1-secu-sessions &   # sessions dans ~/AEIC

BASE=http://127.0.0.1:8099
# P1 — invité sans CSRF : 403 « jeton CSRF invalide », 0 écriture
curl -si -X POST $BASE/admin/compta/inventaire/t1/decide \
  -d "source=Pepsi&target=Pepsi%20sans%20sucre&decision=fusion&origine=modele" | head -1

# P2 — élève (login sans 2FA) puis POST avec CSRF valide (token repris du lien logout?t=) : 403
JAR=/tmp/eleve.jar; CSRF=$(curl -s -c $JAR $BASE/login | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b $JAR -c $JAR -X POST $BASE/login -d "email=secu-eleve@exemple.fr&password=Password123456&_csrf=$CSRF"
T=$(curl -s -b $JAR -c $JAR $BASE/ | grep -o 'logout?t=[a-f0-9]*' | head -1 | cut -d= -f2)
curl -si -b $JAR -X POST $BASE/admin/compta/inventaire/t1/decide \
  -d "_csrf=$T&source=Pepsi&target=Pepsi%20sans%20sucre&decision=fusion&origine=modele" | head -1   # → 403

# P4 — SUPERADMIN : login + 2FA (code = Totp::code($SECRET)), puis :
#   POST sans _csrf → 403 ; POST avec _csrf + decision=peut-etre → 302, flash error, 0 audit ;
#   POST avec _csrf + decision=ignorer + score=42 → details.score = null.
#   (déroulé intégral exécuté le 27/10/2026 — sorties consignées dans le rapport de mission)

# P5 — XSS : remplacer TEMPORAIREMENT data/eval_t1/candidats_fusion.json par
#   {"candidats":[{"a":"<script>alert('T1XSS-A')</script>","b":"Pepsi Max","score":0.91}]}
#   + semer les 2 clés vivantes, GET inventaire → grep -c "<script>alert" = 0
#   (échappé en &lt;script&gt;…&apos;…), PUIS restaurer le fichier d'origine :
sha256sum -c data/eval_t1/SHA256SUMS      # → 12/12 OK

# ── Tests du lot C (post-revue, rien n'a changé) ─────────────────────────
sed 's|name="DB_HOST" value="127.0.0.1"|name="DB_HOST" value="127.0.0.1:3307"|' phpunit.xml > .phpunit-t1-local.xml
DB_PASS=aeic php phpunit.phar -c .phpunit-t1-local.xml --no-coverage --filter "T1SuggestionsTest|T1DecideTest"
# → OK (19 tests, 88 assertions)
rm -f .phpunit-t1-local.xml
```

## 3. Constats mineurs (aucun correctif appliqué — recommandations)

- **MINEUR/observation** — un POST `/admin/*` en invité sans token renvoie `403 « jeton CSRF invalide »` avant la redirection vers `/login` (ordre CSRF → auth dans `Router::dispatch`). Comportement préexistant du routeur pour TOUTES les routes admin, sans divulgation (message générique) ; ne justifie pas de toucher au routeur commun.
- **MINEUR/observation** — `score` accepte les notations exponentielles (`is_numeric('1e3')` → 1000 → clampé `null`) : correct par construction (bornage [0,1]), aucun risque.
- **MINEUR/observation** — l'audit `t1.pair_feedback` stocke l'IP dans la colonne dédiée `ip_address` (comme toutes les écritures d'audit du projet, parité exigée par le lot A) — conforme au contrôle « pas d'IP dans `details` ».
- **Hors périmètre relevé** : aucun. Les en-têtes de sécurité (CSP/HSTS, `SecurityHeaders` préexistant) couvrent la page comme toutes les autres ; rien à ajouter côté lot C.

## 4. Traçabilité de la revue

- Jeu figé `data/eval_t1/` : vérifié **12/12 OK avant ET après** le test XSS (livrable temporaire restauré byte-exact ; `candidats_fusion.json` n'est pas listé dans SHA256SUMS — livrable régénérable du lot B — restauré néanmoins à l'identique).
- Base de test : `maintenance_mode` remis à `1` (état du dump) ; le seed de revue reste dans `aeic_test` (jetable, re-reset par les tests).
- Serveur de preuve arrêté, artefacts (`cache/t1-secu-*`, log, sessions, secret TOTP) purgés ; rien écrit hors `~/AEIC` (sessions PHP redirigées dans `~/AEIC/cache`).
- Git : aucun commit/push (contrainte §1 respectée) ; aucun fichier du repo modifié par la revue (seul ajout : ce rapport).
