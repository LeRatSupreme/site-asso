# Règles du projet AEIC

## Git

- À chaque modification de code, faire un commit puis un `git push` immédiatement après (jamais de code modifié non commité/poussé en fin de tâche).
- Messages de commit en français, courts et descriptifs (style des commits existants).
- Branche de travail : `experiments` (poussée vers `origin/experiments`).
- Ne jamais committer : `.env`, `.kilo/`, fichiers temporaires.

## Déploiement VPS

- Après le push, déployer sur le VPS : `ssh ubuntu@51.254.129.80 "cd ~/AEIC && git pull --ff-only origin experiments"`.

## Config serveur (VPS, hors repo)

- PHP mod_php (Apache) : `/etc/php/8.3/apache2/conf.d/99-aeic-uploads.ini` fixe `upload_max_filesize = 12M` et `post_max_size = 14M` — indispensable au scan de photos de factures (le défaut 2 M faisait échouer l'upload avec « erreur 1 »). Après modification : `sudo systemctl reload apache2`.
