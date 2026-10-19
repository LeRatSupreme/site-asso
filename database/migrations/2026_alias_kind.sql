-- Appellations : distinguer les libellés longs de facture/scan (page
-- « Ajouter une appellation ») des alias produits courts (page
-- « Mapping des libellés »). Les deux résolvent pareil aux imports
-- (ProductAlias::resolve) — seule la présentation change.
ALTER TABLE product_aliases ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'label' AFTER category;
