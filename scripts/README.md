# Scripts AEIC

Scripts d'exploitation du site AEIC.

## `backup.sh` — Sauvegarde (dump MariaDB + uploads)

Script bash (pas de PHP) qui réalise :

1. un **dump de la base** via `mysqldump` (gzip, nom horodaté) ;
2. une **archive `tar.gz`** de `public/assets/uploads/` ;
3. une **rotation** : suppression des archives de plus de 14 jours
   (paramétrable via `BACKUP_RETENTION_DAYS`).

Les credentials sont lus depuis `config.env` (clés `DB_HOST`, `DB_NAME`,
`DB_USER`, `DB_PASS`) — **aucun mot de passe en dur** dans le script.
Surcharge possible par variables d'environnement (`DB_*`).

Le mot de passe MySQL est transmis via un fichier temporaire
`--defaults-extra-file` (permissions `0600`), jamais en ligne de commande
(non visible dans `ps`/`/proc`).

### Planification en cron

```bash
crontab -e
```

Ajouter (chaque nuit à 3 h00) :

```
0 3 * * *  /var/www/aeic/scripts/backup.sh >> /var/www/aeic/logs/backup.log 2>&1
```

> Rendre le script exécutable une fois : `chmod +x /var/www/aeic/scripts/backup.sh`

Les archives sont écrites dans `/var/www/aeic/backups/` (ignoré par Git).

### Restauration

```bash
cd /var/www/aeic/backups

# 1) Décompacter le dump SQL
gunzip -k aeic_db_YYYYMMDD-HHMMSS.sql.gz

# 2) Restaurer la base
mysql -u aeic -p aeic < aeic_db_YYYYMMDD-HHMMSS.sql

# 3) (Optionnel) Restaurer les uploads
tar -xzf aeic_uploads_YYYYMMDD-HHMMSS.tar.gz -C /var/www/aeic/public/assets/
```

> Penser à remettre les bonnes permissions après restauration :
> `sudo chown -R www-data:www-data /var/www/aeic/public/assets/uploads`.

### Test manuel

```bash
cd /var/www/aeic
./scripts/backup.sh
ls -lh backups/
```

## `sync_sumup_sales.php` — Synchro automatique des ventes SumUp

Récupère les ventes SumUp **en direct** (API, fenêtre glissante) et les
insère dans la table `sales` via le même chemin que l'import CSV : même
déduplication (clé unique `uniq_sale_line`), même traçabilité
(`import_batches`, fichier « synchro-api-sumup.json »). Remplace l'import
manuel des rapports : les ventes apparaissent dans la compta moins d'une
minute après le paiement.

**Idempotent** : relancer n'insère jamais deux fois une vente (dédup par
« Réf. transaction »). Un CSV couvrant une période déjà synchronisée reste
refusé/averti par l'anti-chevauchement des lots.

### Configuration (`config.env`)

```
SUMUP_API_KEY=sup_sk_…                 # vide/absente = synchro désactivée
# SUMUP_MERCHANT_CODE=MCMNHAA3         # optionnel, découvert automatiquement
# SUMUP_SYNC_LOOKBACK_HOURS=24         # fenêtre de recherche (défaut 24)
```

> Clé API : me.sumup.com → Settings → **For Developers → API keys**.
> Ne jamais committer la clé (config.env est ignoré par Git).

### Planification en cron (chaque minute)

```bash
crontab -e
```

```
* * * * *  cd /home/ubuntu/AEIC && php scripts/sync_sumup_sales.php >> /home/ubuntu/AEIC/logs/sumup-sync.log 2>&1
```

Un verrou empêche deux exécutions parallèles (cron lent → tick suivant
ignoré). Sortie 0 = OK ou « rien à faire », 1 = erreur (clé invalide,
API injoignable… → visible dans le log).

### Test manuel / diagnostic

```bash
cd /home/ubuntu/AEIC
php scripts/sync_sumup_sales.php --dry-run   # ne touche pas à la base
php scripts/sync_sumup_sales.php             # exécution réelle
tail -5 logs/sumup-sync.log
```

