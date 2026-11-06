-- ============================================================
--  AEIC — Prise d'argent « chez soi » (kiosque) : type PRISE + coupures
--
--  Étape DONNÉES du chantier « kiosque : prise d'argent chez soi ».
--
--  1. Étend l'ENUM de cash_movements avec le type PRISE :
--     transfert SIGNÉ caisse <-> « chez soi », même convention que
--     DEPOT/AJUSTEMENT — négatif = argent pris chez soi, positif =
--     argent ramené. Aucune ligne existante n'est modifiée ni réécrite.
--
--  2. Crée cash_movement_breakdown : détail par coupures en CENTIMES
--     ENTIERS (zéro arrondi flottant), attaché à n'importe quel
--     mouvement de caisse (PRISE mais aussi DEPOT banque).
--
--  Idempotent : le MODIFY vers un ENUM identique est un no-op, le
--  CREATE TABLE est IF NOT EXISTS. Sans danger à rejouer.
--  À appliquer sur une base existante :
--      mysql -u aeic -p aeic < database/migrations/2026_cash_prise.sql
-- ============================================================

-- PRISE est ajouté EN FIN d'ENUM : c'est un changement de métadonnées
-- seules (aucune ligne réécrite — vérifié sur MariaDB 10.11 : eligible
-- ALGORITHM=INSTANT). L'insérer au milieu de la liste imposerait
-- ALGORITHM=COPY (réécriture complète de la table, ce que la contrainte
-- « sans réécriture de données existantes » interdit). L'ordinal ENUM
-- n'a aucun impact fonction : le code compare des chaînes
-- (CashMovement::TYPE_PRISE), jamais l'ordinal.
ALTER TABLE cash_movements
    MODIFY COLUMN type ENUM('FOND','DEPOT','AJUSTEMENT','PRISE') NOT NULL;

-- Détail par coupures d'un mouvement de caisse.
--   unit_cents : valeur de la coupure EN CENTIMES — liste verrouillée
--                par CHECK aux 12 valeurs légales (PAS de 100/200/500 €,
--                choix produit assumé) : 1c 2c 5c 10c 20c 50c 1€ 2€ 5€
--                10€ 20€ 50€.
--   qty        : nombre de pièces/billets (>= 1 ; une coupure saisie à 0
--                n'est simplement pas insérée).
-- Les quantités et valeurs sont entières : tout total est un multiple
-- exact du centime. Invariant garanti par le service (CashLedger) :
--   ABS(amount) = SUM(unit_cents * qty) / 100  exactement
-- (amount est signé — négatif = sortie — les coupures sont des
--  quantités positives).
-- Détail OPTIONNEL : un mouvement sans coupures reste légal (rétro-
-- compatibilité des dépôts existants) ; une prise chez soi l'exige
-- (contrôle applicatif bloquant, le CHECK n'est qu'un dernier filet).
-- Lignes immuables comme cash_movements : pas d'UPDATE ni de DELETE
-- applicatif — une correction passe par un nouveau mouvement.
CREATE TABLE IF NOT EXISTS cash_movement_breakdown (
    movement_id VARCHAR(255) NOT NULL,
    unit_cents  SMALLINT UNSIGNED NOT NULL,
    qty         INT UNSIGNED NOT NULL,
    PRIMARY KEY (movement_id, unit_cents),
    KEY idx_cmb_unit (unit_cents),
    CONSTRAINT fk_cmb_movement FOREIGN KEY (movement_id) REFERENCES cash_movements (id),
    CONSTRAINT chk_cmb_unit CHECK (unit_cents IN (1,2,5,10,20,50,100,200,500,1000,2000,5000)),
    CONSTRAINT chk_cmb_qty CHECK (qty >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
