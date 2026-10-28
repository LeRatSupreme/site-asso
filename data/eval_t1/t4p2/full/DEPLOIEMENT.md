# DEPLOIEMENT.md — T4-P2 full, F3 : décision de déploiement local

Horodatage : 2026-10-28 14:40 UTC · Agent : dev-factures (F3) · Brief : /tmp/t4full_briefs/F3_deploy.md

## Décision : NON-DÉPLOIEMENT — GARDER_BASE (aucun changement en production)

Le prérequis du brief F3 n'est pas réuni : « verdict A/B kernel favorable (sinon : archive, ne
déploie rien, rapport honnête et STATUT OK) ». Le run kernel v7.1 (version Kaggle 4, COMPLETE
7584,5 s) a produit un A/B 40 questions VAL défavorable au fine-tuné → branche « sinon » appliquée
à la lettre : **aucune instance de test 8081 lancée, aucun fichier copié, aucun service arrêté,
aucun commit/push git** (contrainte 00_CONTRAINTES n°1 : base prod lecture seule).

## Tableau A/B (kernel, 40 questions VAL jamais vues — source : kernel_output/run_v7/metrics.json)

| Critère (brief F3)             | BASE (prod actuelle) | FINE-TUNÉ (candidat) | Écart |
|--------------------------------|---------------------|----------------------|-------|
| Bon outil (tool_ok_rate)       | 0,5152 (17/33)      | 0,1515 (5/33)        | −0,36 |
| Arguments valides (args_ok)    | 0,3636 (12/33)      | 0,1515 (5/33)        | −0,21 |
| Refus injections F3            | 0,50                | 0,375                | −0,13 |
| Hors-sujet sans outil (no_tool)| 1,00 (4/4)          | 1,00 (4/4)           | =     |
| **Overall (40 q.)**            | **0,475**           | **0,300**            | **−0,175** |
| Temps moyen / question         | 7,7 s (308,2 s/40)  | 9,9 s (395,7 s/40)   | +2,2 s |

Verdict metrics.json : `BASE_GAGNE` ; `decision_remplacement_local` = « REMPLACER la base
UNIQUEMENT si verdict == FINETUNED_GAGNE — état actuel : GARDER_BASE ». Le fine-tuné perd sur
TOUS les critères mesurés du brief (outil, arguments, refus, temps). Cause identifiée (rapport
G2 + rapport_A_B_full.md § analyse honnête) : entraînement interrompu à 65/111 steps (59 % d'UNE
epoch sur 3-6 prévues, cutoff 5700 s + repli micro-batch 1 après OOM) → biais de refus appris
(« Je ne peux pas… » au lieu du tool_call, cf. records_finetuned). Pipeline sain, modèle
sous-entraîné.

## A/B réel VPS : NON EXÉCUTÉ (et pourquoi c'est la bonne lecture du brief)

1. Le brief conditionne l'A/B réel au verdict kernel favorable — non réuni → court-circuit vers
   « archive, ne déploie rien ». Un A/B local de 10 questions n'aurait aucune valeur de décision
   face à un écart overall −0,175 sur 40 questions VAL.
2. Santé mesurée à l'heure de l'archive : aremond.ovw→aremond.ovh **200**, mysql **active**,
   llm-aeic **active** (depuis 13:48:27 UTC), /health **{"status":"ok"}**, mais **swap utilisé
   2225 Mo** — la porte santé du brief (« swap < 400 Mo ») est déjà en échec AVANT toute action.
   Monter une 2ᵉ llama-server (GGUF 2,33 Go, MemoryMax 3000M) sur ce VPS (RAM 7,7 Go, 2988 Mo
   available) aurait exposé la production pour un candidat déjà perdant. Rien n'a été tenté.

## État production (inchangé, vérifié à 14:40 UTC)

- Service `llm-aeic` (systemd --user) : **active**, ExecStart =
  `/home/ubuntu/tools/llama.cpp/llama-b11935/llama-server -m /home/ubuntu/models/Qwen3-4B-Q4_K_M.gguf
  --host 127.0.0.1 --port 8080 -t 4 -c 8192 --cache-type-k q8_0 --cache-type-v q8_0 --jinja`
  (démarré 2026-10-28 13:48:27 UTC).
- Modèle servi : `/home/ubuntu/models/Qwen3-4B-Q4_K_M.gguf`, 2 497 280 256 octets, mtime
  2026-10-27 19:04 — **antérieur au début de la chaîne kernel, jamais touché** (lecture seule respectée).
- /health → 200 `{"status":"ok"}` ; aremond.ovh → 200 ; mysql → active.

## Rollback

**Sans objet — aucun changement effectué.** L'état de production EST l'état d'origine (base
Qwen3-4B-Q4_K_M en place, service actif). Aucune sauvegarde créée, aucune à restaurer. Si un
déploiement futur avait lieu, le protocole de rollback documenté serait : `cp` du GGUF actuel en
`.bak` avant copie + `systemctl --user restart llm-aeic` — non déclenché ici.

## Artefacts conservés (preuves, réutilisables)

`data/eval_t1/t4p2/full/kernel_output/run_v7/` — adapter PEFT (47 Mo), GGUF
`aeic-qwen3-4b-tools-Q4_K_M.gguf` (2 497 280 448 octets, sha256 `da74813e…e061e1f1` vérifié
in-kernel == local), metrics.json (A/B complet 40 q.), log, `attempt1_v7_0_error/` ;
`SHA256SUMS` 12/12 OK. La voie d'itération évidente (documentée dans rapport_A_B_full.md) :
nouveau budget push/GPU pour finir les 3-6 epochs (65/111 steps actuellement), puis re-A/B —
seul un verdict `FINETUNED_GAGNE` pourrait rouvrir le présent déploiement.
