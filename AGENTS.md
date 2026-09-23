# Règles du projet AEIC

## Git

- À chaque modification de code, faire un commit puis un `git push` immédiatement après (jamais de code modifié non commité/poussé en fin de tâche).
- Messages de commit en français, courts et descriptifs (style des commits existants).
- Branche de travail : `experiments` (poussée vers `origin/experiments`).
- Ne jamais committer : `.env`, `.kilo/`, fichiers temporaires.

## Déploiement VPS

- Après le push, déployer sur le VPS : `ssh ubuntu@51.254.129.80 "cd ~/AEIC && git pull --ff-only origin experiments"`.
