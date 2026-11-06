<?php

declare(strict_types=1);

/**
 * FAQ de l'assistant conversationnel PUBLIC de l'AEIC (fast-path).
 *
 * Ce fichier est DÉDIÉ et volontairement éditable : chaque entrée dont un
 * déclencheur apparaît dans la question est répondue INSTANTANÉMENT par
 * PublicAssistant, sans aucun appel au llama-server local. Les faits
 * reprennent uniquement des informations PUBLIQUES (mêmes faits que le
 * prompt app/config/assistant_public.php).
 *
 * Format : liste ORDONNÉE d'entrées
 *   ['intention' => '...', 'declencheurs' => [...phrases...], 'reponse' => '...'].
 *
 * La clé « intention » (FAQ-SCORE-20, 06/11) nomme l'intention SERVIE
 * par l'entrée — elle alimente le champ « intention » de la trace
 * publique (taux de déroutage mesurable en KPI) et la classe
 * conversationnelle du matcher.
 *
 * Règles de rédaction à conserver si édition :
 *  - déclencheurs écrits DÉJÀ NORMALISÉS : minuscules sans accents, sans
 *    ponctuation, un seul espace entre les mots (la question est
 *    normalisée pareil avant comparaison par sous-chaîne) ;
 *  - le matcher (PublicAssistant::chercherFaq) agrège PAR INTENTION :
 *    score d'une entrée = SOMME des longueurs de ses déclencheurs
 *    matchés ; à égalité de score, la PREMIÈRE entrée du fichier gagne —
 *    c'est pourquoi les entrées conversationnelles (politesse, merci,
 *    salutations — déclencheurs les plus génériques) sont volontairement
 *    en DERNIER : elles ne gagnent QUE si aucune intention métier n'a
 *    matché (« Bonjour, où sont les jeux ? » → jeux, pas l'accueil) ;
 *  - une intention NON SERVIE (paiement, prix, réappro, allergènes —
 *    registre AssistantIntentions) fait REPLIER la question sur la voie
 *    LLM locale : n'ajoutez JAMAIS ici un déclencheur lexical pour
 *    « capter » une question de prix/paiement sans la donnée pour y
 *    répondre (bug « cafét* » fourre-tout, selfreview 06/11 09h) ;
 *  - réponses TRÈS COURTES (1 à 2 phrases), sans émoji ni symbole
 *    décoratif, orientant vers la bonne page du site.
 */

return [
    [
        'intention' => 'identite',
        'declencheurs' => [
            'aeic',
            'bde',
            'c est quoi l aeic',
            'qu est ce que l aeic',
            'c est quoi le bde',
            'qu est ce que le bde',
            'qui etes vous',
            'qui es tu',
            'presente toi',
            'presente l asso',
        ],
        'reponse' => "L'AEIC, c'est l'association des étudiants en informatique de l'IUT de Calais : le BDE info du BUT Informatique de l'ULCO. Présentation complète sur la page /presentation du site.",
    ],
    [
        'intention' => 'adresse',
        'declencheurs' => [
            'adresse',
            'ou vous trouver',
            'ou etes vous',
            'ou se trouve',
            'ou est l aeic',
            'localisation',
            'rue louis david',
            'etablissement',
        ],
        'reponse' => "Nous sommes au 19 rue Louis David, 62100 Calais, à l'IUT. La page /presentation du site explique comment nous trouver.",
    ],
    [
        // ADHÉSION — réponse FIXE validée 31/10 08h09 par le chemin LLM
        // (FAST-07 : 9,8 s de génération pour une réponse immuable →
        // fast-path). Déclencheurs élargis aux fautes courantes : « aderer »
        // (sans h — preuve 08h09), « adesion », racine « cotis » (cotisation).
        // FAST-07 suite (selfreview 06/11 02h) : « participer » et « benevol »
        // sont mappés ICI — l'intention « comment participer aux activités »
        // est SERVIE par l'adhésion (« comment s'inscrire »), pas par le
        // programme événements (preuve 06/11 02h : « activites » volait la
        // question ; le score agrégé par intention la rend à l'adhésion).
        'intention' => 'adhesion',
        'declencheurs' => [
            'adhesion',
            'adesion',      // faute courante sans h
            'adher',        // racine : adhérer, j'adhère, adhérents…
            'aderer',       // faute courante sans h (preuve journal 31/10 08h09)
            'inscri',       // racine : s'inscrire, je m'inscris, inscription…
            'cotis',        // racine : cotisation, cotiser
            'participer',   // intention « participer aux activités » → ADHÉSION
            'benevol',      // racine : bénévolat, bénévole (même parcours)
            'membre',
            'devenir membre',
            'comment rejoindre',
            'rejoindre l asso',
            'creer un compte',
            'creer compte',
            'creer mon compte',
            'compte',
        ],
        'reponse' => "L'adhésion est gratuite. Vous pouvez vous inscrire en ligne depuis la page /register.",
    ],
    [
        'intention' => 'evenements',
        'declencheurs' => [
            'evenement',
            'evenements',
            'evennement',   // faute courante double n (KB-06 : preuves 30/10 00:03
            'evennements',  // et 31/10 04:03 passées au LLM avec scorie EN/FR)
            'evenem',       // radical tronqué (mission 03/11) : le match par
                            // sous-chaîne pardonne les fautes en SUFFIXE mais
                            // PAS les mots tronqués — « c kand le prochain
                            // evenemen de lasso ? » (preuve 03/11 02h) dérivait
                            // en LLM 11,8 s faute de ce radical.
            'evenn',        // idem, variante double n : « evennemen »…
            'soiree',
            'soirees',
            'soire',        // racine : « soiré » (faute), soirée…
            'sortie',
            'sorties',
            'afterwork',
            'afterworks',
            'bbq',
            'bowling',
            'bar',
            'nuit de l info',
            'nuit de linfo',
            'activite',
            'activites',
            // Formulations familières (KB-06) — volontairement LONGUES
            // (14+) pour ne pas détourner « la cafétéria est ouverte ce
            // week-end ? » (racine cafétéria + semaine/week-end → entrée
            // cafétéria) : seules les intentions « programme » matchent.
            'quoi ce week end',
            'quoi ce weekend',
        ],
        'reponse' => "Au programme : afterworks, BBQ, bowling, sorties bar et la Nuit de l'Info. Le calendrier complet est sur la page /events du site.",
    ],
    [
        // CAFÉTÉRIA — entrée CANONIQUE UNIQUE (fusion 30/10 des anciennes
        // entrées « cafétéria générique » + « horaires cafétéria »,
        // cause du bug fastpath_incomplet prouvé 2× en A/B : « a quelle
        // heur ouvre la cafeterai le vendredi » et « cafet ouvre a kel
        // heure le mardi » étaient servies en 132 ms SANS aucun horaire
        // — les fautes (« heur », « cafeterai ») cassaient les
        // déclencheurs longs de l'entrée horaires et laissaient gagner
        // le déclencheur court « cafe » de l'entrée générique).
        // UNE seule vérité : la réponse embarque TOUJOURS les 4
        // créneaux, donc toute racine cafétéria (« cafet », « cafe »,
        // « cafeteria »…) suffit — plus de concurrence entre entrées
        // sœurs, inutile d'ajouter au matcher une règle « racine
        // cafétéria ET token temporel » (étage court abandonné au
        // profit de cette fusion). Les déclencheurs d'intention
        // temporelle ci-dessous restent pour les questions SANS racine
        // cafétéria (« a quelle heure… », « les créneaux », …).
        // Anti-hijack conservé : les mots seuls « ouvre »/« ferme »/
        // « ouvert »/« quand »/« heure »/« paus » restent ABSENTS (règle
        // du déclencheur le plus long : ils détourneraient « quand
        // ouvre la soirée » → événements, cf. fix « jeu » →
        // « déjeuner »). « cafe » est sous-chaîne de « cafet » et
        // « cafeteria » : il attrape aussi les fautes « cafeterai »,
        // « la cafet »…
        // NB (06/11, repli fast-path) : cette entrée ne peut PLUS capturer
        // les questions d'intention non servie (paiement, prix, réappro —
        // registre AssistantIntentions) : elles replient sur la voie LLM
        // locale, jamais sur ces horaires.
        'intention' => 'cafeteria',
        'declencheurs' => [
            // Racines cafétéria.
            'cafeteria',
            'cafet',
            'cafe',
            'boisson',
            'boissons',
            'encas',
            'manger',
            'boire',
            'nourriture',
            // Intention temporelle — formes LONGUES sûres seulement.
            'horaire',
            'horaires',
            'oraire',       // h muet (mission 03/11) : « les oraires… » (preuve
                            // 02/11 19h : 24,3 s en LLM) — le h initial muet
                            // omis est la troncature la plus courante du mot.
            'heures ouverture',
            'heures d ouverture',
            'a quelle heure',
            'quand ouvert',
            'quand ouvre la cafeteria',
            'cafeteria ouvre',
            'ouverture cafeteria',
            'ouverture de la cafeteria',
            'horaires de la cafeteria',
            'horaire de la cafeteria',
            'creneau',
            'creneaux',
            'pauses cafet',
        ],
        'reponse' => "L'AEIC tient une cafétéria à prix étudiant à l'IUT de Calais, avec boissons et encas. Elle ouvre aux pauses : 8h20-8h30, 9h50-10h10, 14h20-14h35 et 15h55-16h15, du lundi au vendredi ; fermée le week-end et les jours fériés.",
    ],
    [
        // NB : les noms de jeux SEULS ('tetris', 'wordle', 'snake',
        // 'memory', 'enigme') sont volontairement ABSENTS : « c'est quoi
        // Tetris ? » doit tomber sur le LLM (culture générale), tandis que
        // « jeu/jeux/jouer/classement » renvoie la liste ; les expressions
        // « un jeu », « votre jeu », « jeu gratuit » sont matchées par sous-chaîne,
        // donc pas besoin de les décliner (« un jeu de tetris », etc.).
        'intention' => 'jeux',
        'declencheurs' => [
            'un jeu',
            'votre jeu',
            'jeu gratuit',
            'jeux',
            'jouer',
            'classement',
        ],
        'reponse' => "Le site propose des jeux en ligne gratuits : Wordle (FR/EN), Memory, Snake, Tetris et une énigme quotidienne avec classement. C'est sur la page /jeux.",
    ],
    [
        'intention' => 'contact',
        'declencheurs' => [
            'contact',
            'contacter',
            'vous joindre',
            'joindre',
            'email',
            'mail',
            'ecrire',
        ],
        'reponse' => "Tu peux nous écrire à calais.aeic@gmail.com. Toutes les infos de l'asso sont aussi sur la page /presentation du site.",
    ],
    [
        // CRÉATEUR DU SITE / TECHNO — question réelle du widget public
        // (journal 28/10, signalée 4 nuits par les selfreview). Faits
        // 100 % vérifiables publiquement : footer (« Développé par
        // Adrien Remond », aremond.ovh) et /legal Article 10 « Site
        // développé par Remond Adrien, étudiant en informatique, dans le
        // cadre d'un projet associatif bénévole ». AUCUNE mention
        // publique de techno (PHP…) → la réponse n'en cite pas.
        // Anti-hijack : chaque déclencheur contient « site » ou
        // « technologie »/« developpe »/« construit » — « qui a créé
        // l'AEIC ? » (l'association) ne matche PAS et reste sur l'entrée
        // identité AEIC ('aeic').
        'intention' => 'site',
        'declencheurs' => [
            'qui a cree ce site',
            'qui a cree le site',
            'qui a fait le site',
            'qui a fait ce site',
            'quelle technologie',
            'qui a developpe',
            'qui a developpe le site',
            'avec quoi le site',
            'comment le site a ete fait',
            'qui a construit le site',
        ],
        'reponse' => "Le site de l'AEIC a été développé par Adrien Remond, étudiant en informatique, dans le cadre d'un projet associatif bénévole. Les crédits complets sont sur la page /legal du site.",
    ],
    // Politesse / smalltalk — placées juste AVANT les salutations (les
    // génériques restent en dernier). Avec le score agrégé par intention
    // + classe conversationnelle (FAQ-SCORE-20), politesse, merci et
    // salutation ne gagnent QUE si aucune intention métier n'a matché —
    // « Bonjour, pourriez-vous me dire quels sont les jeux ? » va aux
    // JEUX (paire contrastée 05/11 13h, épinglée en test).
    [
        'intention' => 'politesse',
        'declencheurs' => [
            'comment ca va',
            'comment sa va',
            'ca va',
            'sa va',
        ],
        'reponse' => "Ça va bien, merci ! Et toi ? Si tu as une question sur l'AEIC, ses événements, sa cafétéria ou ses jeux, je suis là.",
    ],
    [
        'intention' => 'merci',
        'declencheurs' => [
            'merci',
        ],
        'reponse' => "De rien, avec plaisir ! Les infos de l'asso sont aussi sur les pages /presentation, /events et /jeux du site.",
    ],
    [
        'intention' => 'salutation',
        'declencheurs' => [
            'bonjour',
            'salut',
            'coucou',
            'hello',
            'bonsoir',
            'hey',
        ],
        'reponse' => "Salut ! Je suis l'assistant de l'AEIC. Pose ta question sur l'asso, ses événements, la cafétéria, les jeux ou l'adhésion.",
    ],
];
