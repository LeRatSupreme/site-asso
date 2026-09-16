-- ============================================================
--  AEIC — Import SumUp blindé : anti-réimport + dédoublonnage fiable
--
--  1) Les NULL de `sales.description` court-circuitaient la clé unique
--     uniq_sale_line (MySQL ignore les NULL dans les index UNIQUE) :
--     deux lignes sans libellé étaient comptées en double et faussaient
--     le stock théorique. On normalise puis on ferme la colonne.
--  2) Empreinte SHA-256 du fichier importé : un même fichier ne peut
--     plus être importé deux fois (les NULL multiples restent permis
--     pour les anciens lots sans hash).
--
--  À exécuter AVANT `git pull` sur le VPS (ALTER additif, compatible
--  avec le code ancien). À NE PAS rejouer tel quel : les ALTER ne sont
--  pas ré-idempotents (l'UPDATE l'est).
-- ============================================================

-- 1. Normalise les libellés manquants (idempotent).
UPDATE sales SET description = '' WHERE description IS NULL;

-- 2. Ferme définitivement la faille NULL sur la clé unique.
ALTER TABLE sales
    MODIFY description VARCHAR(255) NOT NULL DEFAULT '';

-- 3. Anti-réimport par empreinte de fichier.
ALTER TABLE import_batches
    ADD COLUMN file_hash CHAR(64) NULL AFTER filename,
    ADD UNIQUE KEY uniq_import_hash (file_hash);
