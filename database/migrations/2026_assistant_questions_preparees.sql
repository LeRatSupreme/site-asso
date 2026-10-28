-- =====================================================================
--  AEIC — Migration : questions préparées de l'assistant admin (V1+)
--
--  V1 « rapidité » + STEER V1+ :
--   • GABARIT DE RÉPONSE pré-écrit (gabarit_reponse) + mapping
--     placeholder → chemin JSON (mapping_json) : au fast-path, la
--     phrase est remplie avec le résultat LIVE de l'outil — réponse
--     complète instantanée, zéro rédaction LLM. Auto-généré au
--     « Préparer » (templatysation de la dernière réponse ok du
--     journal), éditable sur la page Agent, avec aperçu live.
--   • PARAMS DE ROUTAGE DYNAMIQUES : {"relatif":"semaine_courante.debut"}
--     recalculés À CHAQUE exécution (« cette semaine », « la semaine
--     dernière », « 7 derniers jours »…) → jamais de réponse périmée.
--     Dates ABSOLLES = params statiques → TTL court 7 jours (contre
--     30 j pour un routage à dates recalculées).
--   • question_norm suit la règle FAQ publique (PublicAssistant::
--     normaliserTexte) ; routage revalidé (whitelist 5 outils,
--     scalaires OU expressions relatives, ≤ 4 appels).
--
--  Vocabulaire relatif (fuseau Europe/Paris, résolution fast-path) :
--    semaine_courante.debut|fin     lundi courant → aujourd'hui
--    semaine_precedente.debut|fin   lundi → dimanche précédents
--    jours_glissants:N[.debut|fin]  fenêtre des N derniers jours
--    (borne absente → inférée de la clé du param : debut/fin)
--
--  À appliquer : mysql -u aeic -p aeic < ce_fichier.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS assistant_questions_preparees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_norm VARCHAR(190) NOT NULL,              -- question normalisée (règle FAQ publique) — correspondance EXACTE du fast-path
  question_affichee VARCHAR(500) NOT NULL,          -- variante lisible la plus fréquente (chips, tableaux)
  categorie ENUM('quantite','restock','performance','ventes','marge','autre') NOT NULL DEFAULT 'autre',
  active TINYINT(1) NOT NULL DEFAULT 1,
  ordre INT NOT NULL DEFAULT 0,
  routage_json TEXT NOT NULL,                       -- [{outil, params:{cle: scalaire | {relatif:"..."}}}]
  gabarit_reponse TEXT NULL,                        -- phrase pré-écrite à placeholders {nom} (NULL → formateur générique)
  mapping_json TEXT NULL,                           -- {"placeholder":"chemin.json.pointe"} dans le résultat d'outil
  source_journal TINYINT(1) NOT NULL DEFAULT 0,
  cree_le DATETIME NOT NULL,                        -- capture du routage (TTL : 7 j si dates figées, 30 j sinon)
  UNIQUE KEY uq_question_norm (question_norm),
  INDEX idx_aqp_active_ordre (active, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si une v1 SANS gabarit était déjà posée (MariaDB, idempotent) :
-- ALTER TABLE assistant_questions_preparees
--   ADD COLUMN IF NOT EXISTS gabarit_reponse TEXT NULL AFTER routage_json,
--   ADD COLUMN IF NOT EXISTS mapping_json TEXT NULL AFTER gabarit_reponse;

INSERT IGNORE INTO assistant_questions_preparees
  (question_norm, question_affichee, categorie, active, ordre, routage_json, gabarit_reponse, mapping_json, source_journal, cree_le)
VALUES
  ('il me reste combien de bueno',
   'Il me reste combien de Bueno ?', 'quantite', 1, 1,
   '[{"outil":"stock_produit","params":{"nom":"Bueno"}}]',
   'Il vous reste {stock} unités de {cle_canonique} en stock. Dernier comptage : {dernier_comptage}.',
   '{"stock":"stock","cle_canonique":"cle_canonique","dernier_comptage":"dernier_comptage"}',
   1, NOW()),
  ('combien me reste t il de coca cola',
   'Combien me reste-t-il de Coca Cola ?', 'quantite', 1, 2,
   '[{"outil":"stock_produit","params":{"nom":"Coca Cola"}}]',
   NULL, NULL, 1, NOW()),
  ('qu est ce qu il faut que je rachete',
   'Qu''est-ce qu''il faut que je rachète ?', 'restock', 1, 3,
   '[{"outil":"produits_a_commander","params":{}}]',
   NULL, NULL, 1, NOW()),
  ('comment ca a marche cette semaine',
   'Comment ça a marché cette semaine ?', 'performance', 1, 4,
   '[{"outil":"ventes_periode","params":{"debut":{"relatif":"semaine_courante.debut"},"fin":{"relatif":"semaine_courante.fin"}}}]',
   NULL, NULL, 1, NOW()),
  ('quelles ont ete mes ventes entre le 20 et le 26 octobre 2026',
   'Quelles ont été mes ventes entre le 20 et le 26 octobre 2026 ?', 'ventes', 1, 5,
   '[{"outil":"ventes_periode","params":{"debut":"2026-10-20","fin":"2026-10-26"}}]',
   NULL, NULL, 1, NOW()),   -- dates ABSOLLES (dans la question) : TTL 7 jours
  ('quelles ont ete mes ventes la semaine derniere',
   'Quelles ont été mes ventes la semaine dernière ?', 'ventes', 1, 6,
   '[{"outil":"ventes_periode","params":{"debut":{"relatif":"semaine_precedente.debut"},"fin":{"relatif":"semaine_precedente.fin"}}}]',
   NULL, NULL, 1, NOW()),
  ('quelle est ma marge sur les monster blanche sur les 7 derniers jours',
   'Quelle est ma marge sur les Monster Blanche sur les 7 derniers jours ?', 'marge', 1, 7,
   '[{"outil":"marge_produit","params":{"nom":"Monster Blanche","debut":{"relatif":"jours_glissants:7"},"fin":{"relatif":"semaine_courante.fin"}}}]',
   NULL, NULL, 1, NOW()),
  ('y a t il des doublons de produits en attente de decision',
   'Y a-t-il des doublons de produits en attente de décision ?', 'autre', 1, 8,
   '[{"outil":"doublons_en_attente","params":{}}]',
   NULL, NULL, 1, NOW());
