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
 *   ['declencheurs' => [...phrases...], 'reponse' => '...'].
 *
 * Règles de rédaction à conserver si édition :
 *  - déclencheurs écrits DÉJÀ NORMALISÉS : minuscules sans accents, sans
 *    ponctuation, un seul espace entre les mots (la question est
 *    normalisée pareil avant comparaison par sous-chaîne) ;
 *  - l'entrée gagnante est celle dont le déclencheur LE PLUS LONG est
 *    sous-chaîne de la question (« salut, c'est quoi l'AEIC ? » tombe sur
 *    l'entrée AEIC, pas sur la salutation) ; à égalité de longueur, la
 *    PREMIÈRE entrée du fichier gagne — c'est pourquoi les salutations
 *    (déclencheurs les plus génériques) sont volontairement en DERNIER ;
 *  - réponses TRÈS COURTES (1 à 2 phrases), sans émoji ni symbole
 *    décoratif, orientant vers la bonne page du site.
 */

return [
    [
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
        'declencheurs' => [
            'adhesion',
            'adher',      // racine : adhérer, j'adhère, adhérents…
            'inscri',     // racine : s'inscrire, je m'inscris, inscription…
            'membre',
            'devenir membre',
            'comment rejoindre',
            'rejoindre l asso',
            'creer un compte',
            'creer compte',
            'creer mon compte',
            'compte',
        ],
        'reponse' => "L'adhésion à l'AEIC est gratuite et se fait en ligne depuis la page /register du site.",
    ],
    [
        'declencheurs' => [
            'evenement',
            'evenements',
            'soiree',
            'soirees',
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
    // génériques restent en dernier) : « comment ca va » (13) est plus long
    // que « bonjour » (7), et à égalité (« ca va » vs « salut », 5) la
    // première entrée du fichier gagne.
    [
        'declencheurs' => [
            'comment ca va',
            'comment sa va',
            'ca va',
            'sa va',
        ],
        'reponse' => "Ça va bien, merci ! Et toi ? Si tu as une question sur l'AEIC, ses événements, sa cafétéria ou ses jeux, je suis là.",
    ],
    [
        'declencheurs' => [
            'merci',
        ],
        'reponse' => "De rien, avec plaisir ! Les infos de l'asso sont aussi sur les pages /presentation, /events et /jeux du site.",
    ],
    [
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
