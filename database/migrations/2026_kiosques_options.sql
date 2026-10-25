-- ============================================================
--  AEIC — Kiosques personnalisables : options de visibilité
--  Appliquer AVANT le git pull : mysql -u aeic -p aeic < database/migrations/2026_kiosques_options.sql
--  (si la colonne existe déjà, l'ALTER échoue sans dommage : la vérifier avant)
-- ============================================================

-- Options par kiosque : ce que chaque personne a le droit de VOIR,
-- indépendamment des pages (tuiles) cochées. Stocké en JSON (liste
-- d'ids du catalogue App\Core\Compta\KioskPages::OPTION_IDS) :
--   benefice — bénéfices & marges (récap jour, période, analytique,
--              livre comptable) ;
--   paiements — détail des encaissements liquide/carte (récap jour,
--              période) ;
--   couts    — coûts d'achat (réappro & liste de courses).
-- NULL = kiosque créé AVANT cette fonctionnalité : comportement
-- historique conservé (bénéfice/paiements visibles ; coûts selon la
-- tuile pleine « avec coûts » cochée). Toute nouvelle création ou
-- enregistrement du formulaire écrit une liste (au minimum '[]'),
-- qui prend alors le contrôle complet.
ALTER TABLE kiosques
    ADD COLUMN options TEXT NULL DEFAULT NULL AFTER pages;
