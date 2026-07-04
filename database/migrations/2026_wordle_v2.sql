-- AEIC — Wordle v2 (difficultés + mots longs) + Énigme quotidienne
-- mysql -u aeic -p aeic < database/migrations/2026_wordle_v2.sql

-- ===================== WORDLE_WORDS v2 =====================
-- On recrée la table avec les colonnes length + difficulty.
-- Difficulté : facile = 5 lettres, moyen = 6 lettres, difficile = 7 lettres.

DROP TABLE IF EXISTS wordle_words;

CREATE TABLE wordle_words (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    word        VARCHAR(32) NOT NULL,
    language    ENUM('fr','en') NOT NULL,
    length      TINYINT UNSIGNED NOT NULL,
    difficulty  ENUM('facile','moyen','difficile') NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_word_lang (word, language),
    KEY idx_lang_diff (language, difficulty, is_active),
    KEY idx_lang_len (language, length, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== ÉNIGME QUOTIDIENNE =====================
-- Une devinette par jour, identique pour tous, change à minuit (heure Paris).
-- La sélection du jour est déterministe (date du jour Europe/Paris modulo pool).
-- `answer` contient la/les réponses acceptées, séparées par '|' (normalisées :
-- minuscules, sans accents, sans ponctuation).

CREATE TABLE IF NOT EXISTS daily_enigmas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    question_fr TEXT NOT NULL,
    question_en TEXT NOT NULL,
    answer      VARCHAR(500) NOT NULL,
    hint_fr     VARCHAR(500) DEFAULT NULL,
    hint_en     VARCHAR(500) DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== ~50 ÉNIGMES (FR + EN) =====================
-- Réponses normalisées (minuscules sans accents), synonymes séparés par |.
INSERT INTO daily_enigmas (question_fr, question_en, answer, hint_fr, hint_en) VALUES
('Je suis grand le matin et petit à midi. Qui suis-je ?','I am tall in the morning and short at noon. What am I ?','ombre|ombre portee|shadow','On me voit quand le soleil brille.','You see me when the sun shines.'),
('Plus on me prend, plus je laisse de traces. Qui suis-je ?','The more you take of me, the more you leave behind. What am I ?','empreintes|pas|footsteps|steps','Je guide vos pieds.','I guide your feet.'),
('J''ai un cou mais pas de tête. Qui suis-je ?','I have a neck but no head. What am I ?','bouteille|bouteille en plastique|bottle','Je contiens souvent à boire.','I often hold a drink.'),
('Je commence la nuit et finis le matin. Qui suis-je ?','I start the night and end the morning. What am I ?','n|la lettre n|the letter n','Je suis une lettre.','I am a letter.'),
('Je suis léger comme une plume, mais personne ne peut me tenir longtemps. Qui suis-je ?','I am light as a feather, yet no one can hold me for long. What am I ?','souffle|haleine|breath','Je sors de votre bouche.','I come from your mouth.'),
('Quel mot devient plus court quand on lui ajoute deux lettres ?','What word becomes shorter when you add two letters to it ?','court|short','Pensez au mot lui-même.','Think of the word itself.'),
('J''ai des villes mais pas de maisons, des rivières mais pas d''eau. Qui suis-je ?','I have cities but no houses, rivers but no water. What am I ?','carte|map','On me déplie pour voyager.','You unfold me to travel.'),
('Plus je suis noir, plus je suis propre. Qui suis-je ?','The blacker I am, the cleaner I am. What am I ?','tableau noir|tableau|blackboard|chalkboard','On écrit dessus à l''école.','You write on me at school.'),
('Je n''ai pas de mains mais je frappe. Qui suis-je ?','I have no hands but I strike. What am I ?','horloge|horloge a pendule|clock','Je donne l''heure.','I tell the time.'),
('Quel est le seul nombre qui a autant de lettres que sa valeur ?','What is the only number that has as many letters as its value ?','quatre|four','C''est un chiffre entre 1 et 5.','It is a digit between 1 and 5.'),
('Je peux être chargé mais je ne suis pas une arme. Qui suis-je ?','I can be charged but I am not a weapon. What am I ?','batterie|pile|battery','Je donne de l''énergie.','I give energy.'),
('Je tombe sans faire de bruit. Qui suis-je ?','I fall without making a sound. What am I ?','neige|snow','Je suis blanc en hiver.','I am white in winter.'),
('Je suis toujours devant toi mais tu ne peux pas me voir. Qui suis-je ?','I am always in front of you but you cannot see me. What am I ?','futur|avenir|future','C''est ce qui va arriver.','It is what will come.'),
('Je n''ai pas de yeux mais je vois. Qui suis-je ?','I have no eyes but I see. What am I ?','aimant|aiguille|aimant compas|magnet|compass','Je pointe le nord.','I point north.'),
('On me jette quand on a besoin de moi, on me reprend quand on n''a plus besoin. Qui suis-je ?','You throw me away when you need me and take me back when you do not. What am I ?','ancre|anchor','Je suis attachée aux bateaux.','I am attached to boats.'),
('Je tourne tout le temps sans avancer. Qui suis-je ?','I turn all the time without moving forward. What am I ?','roue|moulin|wheel','Les voitures en ont.','Cars have me.'),
('Quel est le comble de l''horloger ?','What is the clockmaker''s worst nightmare ?','perdre la face|perdre son temps|lose time|lose face','Une expression avec le temps.','An expression with time.'),
('Je suis le début de la fin et la fin de l''espace. Qui suis-je ?','I am the beginning of the end and the end of space. What am I ?','e|la lettre e|the letter e','Je suis une voyelle.','I am a vowel.'),
('Plus on me retire, plus je grandis. Qui suis-je ?','The more you remove from me, the bigger I get. What am I ?','trou|trou d''air|hole','Je suis souvent dans le sol.','I am often in the ground.'),
('J''ai 4 angles mais je ne suis pas un carré quand on m''étire. Qui suis-je ?','I have 4 angles but I am not a square when stretched. What am I ?','rectangle|lozenge|rectangle','Mes côtés opposés sont égaux.','My opposite sides are equal.'),
('Je monte et je descends sans jamais bouger. Qui suis-je ?','I go up and down without ever moving. What am I ?','escalier|escaliers|staircase|stairs','On me grimpe.','You climb me.'),
('Je suis frais quand je suis chaud. Qui suis-je ?','I am fresh when I am hot. What am I ?','pain|pain chaud|bread|fresh bread','Je suis cuit au four.','I am baked.'),
('Quel animal peut rester des mois sans boire ?','Which animal can go months without drinking ?','chameau|dromadaire|camel','Il vit dans le désert.','It lives in the desert.'),
('Je ne suis pas vivant mais je grandis. Je n''ai pas de poumons mais j''ai besoin d''air. Qui suis-je ?','I am not alive but I grow. I have no lungs but I need air. What am I ?','feu|flamme|fire|flame','Je brûle.','I burn.'),
('On me cherche quand on est perdu. Qui suis-je ?','You look for me when you are lost. What am I ?','chemin|route|sens|way|path','Je mène quelque part.','I lead somewhere.'),
('J''ai des branches mais pas de feuilles. Qui suis-je ?','I have branches but no leaves. What am I ?','banque|banque arbre|bank|river bank','Je peux parler d''argent ou de rivière.','I can mean money or a river.'),
('Plus je sèche, plus je suis mouillé. Qui suis-je ?','The drier I get, the wetter I become. What am I ?','serviette|towel','On me trouve dans la salle de bain.','I am found in the bathroom.'),
('Je suis entre le ciel et la terre. Qui suis-je ?','I am between the sky and the earth. What am I ?','air|nuage|air|clouds','Je me respire.','I am breathed.'),
('Quel est l''animal le plus rapide au sol ?','What is the fastest land animal ?','guepard|guepard|cheetah','Il a des taches.','It has spots.'),
('Combien de mois ont 28 jours ?','How many months have 28 days ?','12|tous|twelve|all','Tous les mois en ont au moins 28.','Every month has at least 28.'),
('Je suis fait d''eau mais je meurs si je la touche. Qui suis-je ?','I am made of water but I die if I touch it. What am I ?','iceberg|glace|ice','Je suis froid.','I am cold.'),
('Quel mot est toujours mal prononcé ?','Which word is always pronounced wrong ?','mal|faux|wrong','Réfléchissez au mot lui-même.','Think about the word itself.'),
('Je n''ai qu''un seul œil mais je vois loin. Qui suis-je ?','I have only one eye but I see far. What am I ?','telescope|lunette|telescope|spyglass','On regarde les étoiles avec moi.','You look at stars with me.'),
('Je peux être tendu ou détendu. Je retiens les choses. Qui suis-je ?','I can be tense or relaxed. I hold things together. What am I ?','corde|ficelle|rope|string','On m''attache.','I am tied.'),
('Je suis petit le jour et grand la nuit. Qui suis-je ?','I am small by day and big by night. What am I ?','etoile|star','Je brille dans le ciel.','I shine in the sky.'),
('Quel fruit a ses pépins à l''extérieur ?','Which fruit has its seeds on the outside ?','fraise|fraisier|strawberry','Je suis rouge.','I am red.'),
('Je ne suis pas un oiseau mais je vole. Qui suis-je ?','I am not a bird but I fly. What am I ?','avion|drone|plane|drone|kite|cerf volant','Je suis fabriqué par l''homme.','I am man-made.'),
('J''ai une queue mais je ne suis pas un animal. Qui suis-je ?','I have a tail but I am not an animal. What am I ?','comete|fusee|comet|kite','Je traverse le ciel.','I cross the sky.'),
('Plus je partage, plus je deviens grand. Qui suis-je ?','The more I share, the bigger I get. What am I ?','savoir|connaissance|bonheur|knowledge|happiness','Une idée partagée...','A shared idea...'),
('Quel est l''intrus : pomme, banane, carotte, cerise ?','Which one does not belong: apple, banana, carrot, cherry ?','carotte|carrot','C''est un légume.','It is a vegetable.'),
('Je fais peur aux éléphants mais je suis minuscule. Qui suis-je ?','I scare elephants but I am tiny. What am I ?','souris|mouse','Un petit rongeur.','A small rodent.'),
('J''ai des racines mais je ne suis pas un arbre. Qui suis-je ?','I have roots but I am not a tree. What am I ?','dent|cheveux|tooth|hair','Je suis dans la bouche.','I am in the mouth.'),
('Combien de gouttes d''eau peut contenir un verre vide ?','How many drops of water can an empty glass hold ?','1|une|one','Après la première, il n''est plus vide.','After the first, it is no longer empty.'),
('Je me remplis en me vidant. Qui suis-je ?','I fill up as I empty. What am I ?','sablier|hourglass','Je mesure le temps.','I measure time.'),
('Quel est le seul mot masculin qui finit en -trice ?','What is the only masculine word ending in -trice in French ?','un atrice|aucun|none|aucun mot','Réfléchissez bien...','Think carefully...'),
('Je suis le seul à pouvoir dire « je » dans ma langue. Qui suis-je ?','I am the only one who can say « I » in my language. Who am I ?','moi|je|me|i','Pensez au pronom.','Think of the pronoun.'),
('Je n''ai pas de pieds mais je cours. Qui suis-je ?','I have no feet but I run. What am I ?','riviere|fleuve|river|stream','Je transporte de l''eau.','I carry water.'),
('Plus on me donne, plus on m''a. Qui suis-je ?','The more you give me, the more you have. What am I ?','amour|amitie|sourire|love|friendship|smile','Un sentiment.','A feeling.'),
('Je disparais quand on prononce mon nom. Qui suis-je ?','I disappear when you say my name. What am I ?','silence','Je suis l''absence de bruit.','I am the absence of noise.'),
('Je brille le jour mais on me voit surtout la nuit. Qui suis-je ?','I shine by day but I am mainly seen at night. What am I ?','lune|etoile|moon|star','Je ne suis pas le soleil.','I am not the sun.'),
('Quel nombre suit dans la suite : 2, 4, 8, 16, ?','What comes next: 2, 4, 8, 16, ?','32','On double à chaque fois.','You double each time.'),
('Je suis pris avant d''être donné. Qui suis-je ?','I am taken before being given. What am I ?','photo|photographie|photograph|picture','On me cadre.','I am framed.');
