-- ============================================================
--  AEIC — Section « Traçabilité du liquide » du livre comptable
--  (lecture seule — à utiliser par dev-backend dans
--   CashMovement::between() + le rendu du livre)
--
--  CONTRAT (spec validée) : cette section AFFICHE les mouvements de
--  caisse (FOND/DEPOT/AJUSTEMENT/PRISE) et leurs coupures mais ne
--  touche AUCUN total du livre (débit/crédit/solde de trésorerie) :
--  une prise n'est ni recette ni dépense de l'asso. Toutes les
--  requêtes ci-dessous sont des SELECT : aucun UPDATE/DELETE.
--
--  Convention de bornes de période du projet (cf.
--  Sale::dailyRevenueBetween) : jours 'YYYY-MM-DD', bornes inclusives —
--      :from = 'YYYY-MM-DD 00:00:00'
--      :to   = 'YYYY-MM-DD 23:59:59'
--  (bornes optionnelles : omettre les prédicats si NULL, cf.
--   Purchase::between). Idempotents par nature.
-- ============================================================

-- ---------------------------------------------------------------
-- Q1 — Liste chronologique des mouvements de la période
--      (base de CashMovement::between($from, $to) à créer)
--      Chaque ligne : date, libellé type, montant SIGNÉ, auteur.
-- ---------------------------------------------------------------
SELECT m.id, m.type, m.amount, m.label, m.created_by, m.created_at
FROM cash_movements AS m
WHERE m.created_at >= :from
  AND m.created_at <= :to
ORDER BY m.created_at ASC, m.id ASC;

-- ---------------------------------------------------------------
-- Q2 — Sous-lignes « coupures » des mouvements de la période
--      (joindre côté PHP sur movement_id ; ne renvoie que les
--       mouvements ayant un détail — un mouvement sans coupures
--       reste légal). unit_cents DESC = grosses coupures d'abord,
--       ordre d'affichage « 3×20€ · 5×2€ · 12×50c ».
-- ---------------------------------------------------------------
SELECT b.movement_id, b.unit_cents, b.qty
FROM cash_movement_breakdown AS b
JOIN cash_movements AS m ON m.id = b.movement_id
WHERE m.created_at >= :from
  AND m.created_at <= :to
ORDER BY b.movement_id ASC, b.unit_cents DESC;

-- Variante agrégée (une ligne par mouvement, total en centimes) :
-- le formatage « ×20€ » / « ×50c » reste au PHP (CashBreakdown),
-- ici les valeurs restent brutes et entières.
SELECT b.movement_id,
       GROUP_CONCAT(CONCAT(b.qty, '×', b.unit_cents) ORDER BY b.unit_cents DESC SEPARATOR ' · ') AS coupures,
       SUM(b.unit_cents * b.qty) AS total_cents
FROM cash_movement_breakdown AS b
JOIN cash_movements AS m ON m.id = b.movement_id
WHERE m.created_at >= :from
  AND m.created_at <= :to
GROUP BY b.movement_id;

-- ---------------------------------------------------------------
-- Q3 — Soldes de l'en-tête de section / cartes Caisses-Trésorerie
-- ---------------------------------------------------------------

-- Solde « chez soi » (CashLedger::homeTotal) : TOUTES périodes.
-- Signe : les prises sont stockées négatives → chez soi = −Σ(PRISE).
SELECT COALESCE(-SUM(m.amount), 0) AS chez_soi
FROM cash_movements AS m
WHERE m.type = 'PRISE';

-- Prises et dépôts de la période (montants POSITIFS, pour l'en-tête
-- « Pris : X € · Déposé : Y € ») :
SELECT COALESCE(SUM(ABS(m.amount)), 0) AS pris,
       0 AS depose
FROM cash_movements AS m
WHERE m.type = 'PRISE' AND m.created_at >= :from AND m.created_at <= :to
UNION ALL
SELECT 0 AS pris,
       COALESCE(SUM(ABS(m.amount)), 0) AS depose
FROM cash_movements AS m
WHERE m.type = 'DEPOT' AND m.created_at >= :from AND m.created_at <= :to;

-- Solde de caisse complet (équivalent SQL de CashLedger::balance(),
-- mouvements + ventes LIQUIDE en direct — les ventes ne sont jamais
-- dupliquées dans cash_movements) :
SELECT (SELECT COALESCE(SUM(amount), 0) FROM cash_movements)
     + (SELECT COALESCE(SUM(price_ttc), 0) FROM sales WHERE payment_method = 'LIQUIDE')
       AS solde_caisse;

-- ---------------------------------------------------------------
-- Q4 — Contrôle de cohérence (dernier filet, hors rendu) :
--      tout mouvement ayant un détail coupures dont le total
--      (en centimes) diffère de |amount| est signalé (amount est
--      SIGNÉ : négatif = sortie ; les coupures sont des quantités
--      positives). Résultat attendu : VIDE.
-- ---------------------------------------------------------------
SELECT m.id, m.type, m.amount,
       COALESCE(SUM(b.unit_cents * b.qty), 0) DIV 100 AS detail_euros,
       MOD(COALESCE(SUM(b.unit_cents * b.qty), 0), 100) AS detail_centimes
FROM cash_movements AS m
LEFT JOIN cash_movement_breakdown AS b ON b.movement_id = m.id
GROUP BY m.id, m.type, m.amount
HAVING COUNT(b.movement_id) > 0
   AND ABS(m.amount) * 100 <> COALESCE(SUM(b.unit_cents * b.qty), 0);

-- ---------------------------------------------------------------
-- Q5 — Total par coupure sur la période (analytics « traçabilité »,
--      s'appuie sur idx_cmb_unit ; filtre PRISE+DEPOT = sorties
--      physiques de liquide tracées).
-- ---------------------------------------------------------------
SELECT b.unit_cents, SUM(b.qty) AS nb_unites,
       SUM(b.unit_cents * b.qty) AS total_cents
FROM cash_movement_breakdown AS b
JOIN cash_movements AS m ON m.id = b.movement_id
WHERE m.created_at >= :from
  AND m.created_at <= :to
  AND m.type IN ('PRISE', 'DEPOT')
GROUP BY b.unit_cents
ORDER BY b.unit_cents DESC;

-- ---------------------------------------------------------------
-- Q6 — Option : solde cumulé des MOUVEMENTS par ligne (MariaDB 10.2+,
--      fenêtre). ATTENTION : ce cumul n'est PAS le solde de caisse
--      (il ne contient pas les ventes LIQUIDE) — à n'afficher qu'avec
--      cette précision, la spec ne l'exige pas.
-- ---------------------------------------------------------------
SELECT m.id, m.type, m.amount, m.created_at,
       SUM(m.amount) OVER (ORDER BY m.created_at ASC, m.id ASC
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cumul_mouvements
FROM cash_movements AS m
WHERE m.created_at >= :from
  AND m.created_at <= :to
ORDER BY m.created_at ASC, m.id ASC;
