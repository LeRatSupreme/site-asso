<?php

declare(strict_types=1);

namespace App\Core\Assistant;

/**
 * GABARITS de la voie PUBLIQUE (EDT-PUB-13 / STOCK-PUB-14, mission
 * 31/10 soir) : réponses instantanées à ZÉRO tour LLM pour les
 * questions à donnée publique dérivée, appelées par
 * PublicAssistant::executer() AVANT la FAQ et le sémaphore.
 *
 * Deux gabarits, chacun un PORTAGE EXACT des détecteurs/formats du CLI
 * admin (scripts/assistant.py — gabarits livrés le matin même, servis
 * là-bas en 0,5 s alors que la voie publique restait à 10 s de LLM) :
 *  - edt()    : cours/vacances IUT → AssistantOutils::edtIut() (données
 *               EdtCalendar, cache 6 h), même ligne courte sourcée
 *               « Cette semaine : sans cours (vacances universitaires),
 *               reprise le 02/11. Source : edt-ulco.fr » que le CLI ;
 *  - stock()  : « il vous reste des Kinder Bueno ? » →
 *               AssistantOutils::stockProduit() (livre de stock UNIQUE
 *               StockBook — jamais de map ad hoc, règle AGENTS) avec la
 *               MÊME garde de fraîcheur que la voie admin (« comptage
 *               ancien (X j), fiabilité limitée » dès 30 j).
 *
 * EDT-PUB-13-SUITE (mission 02/11) : une question EXPLICITE sur les
 * HORAIRES est PRIORITAIRE sur le gabarit EDT — la question longue
 * réelle « …à quelle heure ouvre la cafétéria lundi matin avant mon
 * premier cours… » (preuves 01/11 08h08, 02/11 02h02 : « étudiant » +
 * « cours » la captait → réponse EDT décalée) retombe sur le fast-path
 * horaires de la FAQ. Le repli EDT sert le cache périmé AVEC sa date
 * (« calendrier du JJ/MM », jour Paris du dernier fetch).
 *
 * GENERIC-PUB-17 (mission 02/11) : troisième gabarit produits() pour
 * les questions GÉNÉRIQUES (« vous avez quoi à boire et à manger à la
 * cafétéria ? », « koi vous avez comme snacks ? » — servies horaires
 * par erreur, 5 preuves) → courte sélection de familles issues du
 * livre UNIQUE (AssistantOutils::stockSelection, MAX ~10, nom +
 * disponibilité, AUCUN prix/coût/marge). Priorité : horaires
 * explicites > produits génériques (les questions « à quelle heure »
 * restent sur le fast-path horaires).
 *
 * Arbitrage STOCK-PUB-14 (orchestrateur 31/10) : la cafétéria est un
 * domaine annoncé de l'assistant public, la disponibilité d'UN produit
 * est une info légitime — mais JAMAIS de liste d'inventaire : réponse
 * par produit demandé, 2 produits MAX (au-delà : invitation à demander
 * un par un), aucun candidat « produits proches » côté public.
 *
 * Fail-soft PARTOUT : question non détectée, données indisponibles
 * (calendrier EDT vide, base injoignable), produit introuvable dans le
 * Extracteur → null → la voie classique (FAQ, puis LLM) reprend — un
 * gabarit ne bloque JAMAIS une question qu'il ne sait pas traiter.
 */
final class PublicGabarits
{
    // -----------------------------------------------------------------
    //  Détection EDT — portage exact de scripts/assistant.py
    //  (_MOTIFS_EDT, _MOTIFS_INTERROGATION_EDT, _MOTS_NON_EDT, mission E3)
    // -----------------------------------------------------------------

    /** Mots signifiant la question porte sur l'EMPLOI DU TEMPS / les
     *  cours de l'IUT (sur texte sans accents) : « edt »/« cours » en
     *  mot entier, « emploi du temps », et depuis la mission E3 (pic de
     *  reprise 02/11) « vacances », « étudiant(s) » et « reprise »
     *  portent SEULS l'intention EDT. */
    private const MOTIF_EDT = '/\bedt\b|\bcours\b|emploi\s+du\s+temps|\bvacances?\b|\betudiante?s?\b|\breprise\b/i';

    /** Marqueurs d'interrogation/temporalité qui, AVEC un mot EDT,
     *  déclenchent le gabarit (la question réelle « il y a cours cette
     *  semaine à l IUT ? » en a trois). */
    private const MOTIF_INTERROGATION_EDT = '/\?|\b(?:est-ce|y\s+a|ya|a-t-il|quand|reprise|vacances|semaine|aujourd|demain|prochain|prochaine|iut|lycee)\b/i';

    /** Mots disqualifiants : une question produits/ventes (« combien
     *  de… », stock, marge…) ne doit JAMAIS partir sur le gabarit EDT —
     *  le fast-path stock garde « combien de X » quantité. */
    private const MOTS_NON_EDT = [
        'combien', 'vente', 'ventes', 'benefice', 'benefices', 'benef',
        'benefs', 'marge', 'marges', 'profit', 'profits', 'perte',
        'pertes', 'gain', 'gains', 'stock', 'reste', 'produit',
        'produits', 'article', 'articles', 'commande', 'commandes',
        'commander', 'racheter', 'acheter', 'prix', 'coute', 'coutent',
        'ca', 'chiffre', 'chiffres', 'euro', 'euros', 'argent',
        'vendu', 'vendus', 'vendue', 'vendues',
    ];

    /** Mots signalant une question EXPLICITE sur les HORAIRES (ouvertures,
     *  pauses, fermetures — même vocabulaire que les déclencheurs longs de
     *  l'entrée cafétéria de la FAQ, fautes tolérées : « heur », « souvre »
     *  pour « s'ouvre »). PRIORITAIRES sur les gabarits EDT et produits :
     *  une question horaires, même longue et pleine de mots EDT (« cours »,
     *  « étudiant »), reste sur le fast-path horaires (EDT-PUB-13-SUITE). */
    private const MOTS_HORAIRES = [
        'horaire', 'horaires', 'heure', 'heures', 'heur',
        'orair', 'oraire', 'oraires',   // h muet tronqué (mission 03/11 :
        // « ske son les oraires douverture… » 24,3 s en LLM — le radical
        // tronqué doit profiter de la MÊME priorité horaires),
        'ouvre', 'ouvres', 'ouvert', 'ouverte', 'ouverts', 'ouvertes',
        'ouvrez', 'ouvrira', 'souvre', 'ferme', 'fermes', 'fermee',
        'fermees', 'fermeture', 'ouverture', 'pauses', 'creneau',
        'creneaux',
    ];

    // -----------------------------------------------------------------
    //  Détection stock — portage exact de scripts/assistant.py
    //  (MOTIFS_STOCK_PRODUIT, MOTS_NON_PRODUITS, _produit_plausible)
    // -----------------------------------------------------------------

    /** Déterminants ouvrant le nom de produit (même séquence que le CLI). */
    private const DETERMINANTS_PRODUIT = "(?:de\s+la\s+|de\s+l'|du\s+|des\s+|d'|de\s+)";

    /** Questions « combien reste-t-il de X », « stock de X », « il y a
     *  du X »… : du plus SPÉCIFIQUE au plus générique (le premier motif
     *  qui matche gagne — les génériques sont filtrés par
     *  MOTS_NON_PRODUITS). Groupe « produit » = tout le reste de la
     *  question après le déterminant. */
    private const MOTIFS_STOCK_PRODUIT = [
        // « Combien reste-t-il de Coca ? », « reste-t-il du coca ».
        "/\b(?:combien\s+)?(?:il\s+)?(?:me\s+|te\s+|nous\s+|vous\s+)?(?:en\s+)?reste(?:-|\s)?t(?:-|\s)?il\s+%DET%(?P<produit>.+)/ui",
        // « combien il reste de X », « tu sais combien il me reste de X ».
        "/\bcombien\s+(?:il\s+)?(?:me\s+|te\s+|nous\s+|vous\s+)?(?:en\s+)?reste.*?%DET%(?P<produit>.+)/ui",
        // « il me reste du X », « il en reste de la X ».
        "/\bil\s+(?:me\s+|te\s+|nous\s+|vous\s+|en\s+)?reste\s+%DET%(?P<produit>.+)/ui",
        // — extension publique (STOCK-PUB-14) : temps/futur (« il vous
        // RESTERAIT encore des X », question réelle du journal 31/10
        // 15:08 partie en réponse salutation faute de gabarit) —
        // « reste\w* » couvre reste/restera/resterait/restez…, « encore »
        // toléré entre le verbe et le déterminant.
        "/\bil\s+(?:me\s+|te\s+|nous\s+|vous\s+|en\s+)?reste\w*\s+(?:encore\s+)?%DET%(?P<produit>.+)/ui",
        // « stock de X », « quel est le stock du X ».
        "/\bstock\s+%DET%(?P<produit>.+)/ui",
        // « il y a (combien) de X ».
        "/\bil\s+y\s+a\s+(?:combien\s+)?%DET%(?P<produit>.+)/ui",
        // « combien de X », « il me reste combien de X » (le plus générique).
        "/\bcombien\s+%DET%(?P<produit>.+)/ui",
    ];

    /** Mots qui signalent une question NON-stock (ventes, marge,
     *  délais…) : si le nom extrait en contient UN, la question part en
     *  voie LLM normale (fail-soft — on ne répond jamais « stock » à une
     *  question de CA/marge/période). Portage du CLI + ADDITIONS
     *  publiques (STOCK-PUB-14) : le vocabulaire des domaines FAQ de
     *  l'assistant public (événements, adhésion, jeux…) ne doit jamais
     *  être avalé par le motif générique « il y a des X ». */
    private const MOTS_NON_PRODUITS = [
        // — portage exact du CLI (scripts/assistant.py) —
        'vente', 'ventes', 'benef', 'benefs', 'benefice', 'benefices',
        'bénéfice', 'bénéfices', 'marge', 'marges', 'ca', 'chiffre', 'chiffres',
        'affaire', 'affaires', 'argent', 'monnaie', 'euro', 'euros', 'profit',
        'profits', 'perte', 'pertes', 'recette', 'recettes', 'depense',
        'depenses', 'dépense', 'dépenses', 'transaction', 'transactions',
        'ticket', 'tickets', 'commande', 'commandes', 'commander', 'racheter',
        'acheter', 'revendre', 'vendre', 'vendu', 'vendus', 'vendue', 'vendues',
        'gagne', 'gagné', 'gagnes', 'gagnés', 'gagnee', 'gagnée', 'encaisse',
        'encaissé', 'monde', 'temps', "aujourd'hui", 'hier', 'demain', 'matin',
        'soir', 'semaine', 'semaines', 'mois', 'jour', 'jours', 'journee',
        'journée', 'annee', 'année', 'an', 'ans', 'adherent', 'adherents',
        'adhérent', 'adhérents', 'membre', 'membres', 'eleve', 'eleves',
        'élève', 'élèves', 'personne', 'personnes', 'client', 'clients',
        'kiosque', 'stand', 'doublon', 'doublons', 'fusion', 'fusions',
        'solde', 'soldes', 'credit', 'credits', 'crédit', 'crédits',
        'stock', 'reste',
        // — additions publiques (domaines FAQ : événements, adhésion,
        //    jeux, contact, horaires — protègent « il y a des soirées ? » —
        //    + vocabulaire métier hors-domaine public : anomalies catalogue,
        //    bénéfice… restent des questions ADMIN, servies par le CLI) —
        'evenement', 'evenements', 'événement', 'événements',
        'evenem', 'evenemen', 'evennemen', 'evenn',   // radicaux tronqués
        // (mission 03/11) : sans eux, « il y a un evenemen vendredi ? »
        // était avalé par le motif stock (« produit introuvable »)
        // AVANT d'atteindre la FAQ événements.
        'soiree', 'soirees', 'soirée', 'soirées', 'sortie', 'sorties',
        'afterwork', 'afterworks', 'bbq', 'bowling', 'adhesion', 'adhésion',
        'cotisation', 'cotisations', 'inscription', 'inscriptions', 'compte',
        'jeu', 'jeux', 'contact', 'mail', 'horaire', 'horaires',
        'orair', 'oraire', 'oraires',   // h muet tronqué (mission 03/11)
        'anomalie', 'anomalies', 'anomalie', 'incoherence', 'incoherences',
        'incohérence', 'incohérences', 'catalogue', 'catalog', 'carte',
        'produit', 'produits', 'article', 'articles',
        'edt', 'cours', 'vacances', 'reprise',
    ];

    /** Séparateurs multi-produits (« Kinder Bueno et Chocapic »). */
    private const MOTIF_SEPARATEUR_PRODUITS = '/\s+(?:et|ou)\s+|,/ui';

    /** Nombre maximum de produits répondus en une question (arbitrage
     *  STOCK-PUB-14 : 1-2 produits, jamais une liste d'inventaire). */
    public const PRODUITS_MAX = 2;

    /** Phrases signalant une question GÉNÉRIQUE sur l'offre cafétéria
     *  (GENERIC-PUB-17) — sur texte sans accents : « à boire », « à
     *  manger », snacks, encas, nourriture, boissons. */
    private const MOTIF_PRODUITS_GENERIQUES = '/a\s+boire|a\s+manger|\bsnacks?\b|\bencas\b|\bnourriture\b|\bboissons?\b/ui';

    /** Mots GÉNÉRIQUES seuls (« des snacks ? », « à boire ») : extraits
     *  comme « produit » par les motifs stock mais sans produit précis
     *  derrière — la question est GÉNÉRIQUE, le gabarit liste la
     *  sélection, pas « produit introuvable ». Un segment n'est générique
     *  que si TOUT ses mots le sont (« coca à boire » garde son produit
     *  précis — « boire »/« manger »/« quoi » et articles inclus). */
    private const MOTS_GENERIQUES = [
        'snack', 'snacks', 'encas', 'nourriture', 'boisson', 'boissons',
        'gouter', 'gouters', 'boire', 'manger', 'quoi', 'comme',
        'quel', 'quelle', 'quels', 'quelles',
        'un', 'une', 'des', 'du', 'de', 'd', 'la', 'le', 'les', 'l',
        'et', 'ou', 'a', 'au', 'aux', 'en', 'y', 'pour',
    ];

    // -----------------------------------------------------------------
    //  Gabarit edt — cours/vacances IUT (EDT-PUB-13)
    // -----------------------------------------------------------------

    /**
     * Réponse gabarit à une question cours/EDT/vacances de l'IUT, ou
     * null (question non-EDT, données indisponibles, erreur) → voie
     * classique (FAQ puis LLM). Même détection, mêmes arguments et
     * même mise en forme que le CLI admin (question_edt_iut /
     * _formater_edt_rapide) : les deux voies servent la même réponse.
     */
    public static function edt(string $question): ?string
    {
        $norm = str_replace('’', "'", PublicAssistant::sansAccents($question));

        // PRIORITÉ horaires (EDT-PUB-13-SUITE) : une question EXPLICITE
        // sur les horaires n'est JAMAIS avalée par le gabarit EDT, même
        // longue et pleine de mots EDT — le fast-path horaires (FAQ) la
        // garde (preuves 01/11 08h08, 02/11 02h02).
        if (self::questionHoraires($question)) {
            return null;
        }
        if (preg_match_all("/[a-z0-9']+/u", $norm, $mots) !== false) {
            foreach ($mots[0] as $mot) {
                if (in_array($mot, self::MOTS_NON_EDT, true)) {
                    return null;
                }
            }
        }
        if (preg_match(self::MOTIF_EDT, $norm) !== 1) {
            return null;
        }
        if (preg_match(self::MOTIF_INTERROGATION_EDT, $norm) !== 1) {
            return null;
        }

        $arguments = [];
        if (preg_match('/\b(info|gea|geii)\b/i', $norm, $dept) === 1) {
            $arguments['dept'] = strtolower($dept[1]);
        }
        if (preg_match('/\bprochaine?\b/i', $norm) === 1) {
            $arguments['semaine'] = 'prochaine';
        } elseif (preg_match('/\bsemaine\b/i', $norm) === 1) {
            $arguments['semaine'] = 'courante';
        } elseif (preg_match('/\b(?:aujourd|demain)\b/i', $norm) === 1) {
            $arguments['semaine'] = 'aujourdhui';
        }

        try {
            $donnees = AssistantOutils::edtIut(
                $arguments['dept'] ?? 'tous',
                $arguments['semaine'] ?? 'aujourdhui'
            );
        } catch (\Throwable) {
            return null; // fail-soft : la voie LLM reprend
        }
        if ($donnees === null) {
            return null;
        }

        return self::formaterEdt($donnees, $arguments);
    }

    /**
     * Portage exact de _formater_edt_rapide (scripts/assistant.py) :
     * ligne courte factuelle, tout champ absent a un repli neutre,
     * aucune date inventée (prochain jour de cours tel que publié),
     * source edt-ulco.fr citée. Le CLI admin doit servir la MÊME ligne.
     *
     * @param array<string, mixed> $donnees    Réponse AssistantOutils::edtIut().
     * @param array<string, string> $arguments dept/semaine détectés.
     */
    public static function formaterEdt(array $donnees, array $arguments): string
    {
        $semaine = (string) ($arguments['semaine'] ?? 'aujourdhui');
        $entete = [
            'courante'  => 'Cette semaine',
            'prochaine' => 'La semaine prochaine',
            'aujourdhui' => "Aujourd'hui",
        ][$semaine] ?? "Aujourd'hui";

        $dept = (string) ($arguments['dept'] ?? 'tous');
        if ($dept !== 'tous') {
            $entete .= ' (' . strtoupper($dept) . ')';
        }

        $prochain = $donnees['prochain_jour_cours'] ?? null;
        $reprise = (is_string($prochain) && strlen($prochain) === 10)
            ? substr($prochain, 8, 2) . '/' . substr($prochain, 5, 2)
            : '';

        if ((bool) ($donnees['hors_horizon'] ?? false)) {
            $ligne = $entete . " : pas encore publié dans l'EDT (horizon d'affichage limité).";
            if ($reprise !== '') {
                $ligne .= " Prochain jour de cours publié : {$reprise}.";
            }
        } elseif ((bool) ($donnees['avec_cours'] ?? false)) {
            $details = [];
            if (is_int($donnees['jours_actifs'] ?? null)) {
                $details[] = $donnees['jours_actifs'] . ' jour' . ($donnees['jours_actifs'] > 1 ? 's' : '') . ' de cours';
            }
            if (is_int($donnees['creneaux'] ?? null)) {
                $details[] = $donnees['creneaux'] . ' créneau' . ($donnees['creneaux'] > 1 ? 'x' : '');
            }
            $ligne = $entete . ' : cours';
            if ($details !== []) {
                $ligne .= ' (' . implode(', ', $details) . ')';
            }
            $ligne .= '.';
            if ($reprise !== '') {
                $ligne .= " Prochain jour de cours : {$reprise}.";
            }
        } elseif ($reprise !== '') {
            $ligne = $entete . " : sans cours (vacances universitaires), reprise le {$reprise}.";
        } else {
            $ligne = $entete . ' : sans cours (vacances universitaires), reprise non publiée pour l\'instant.';
        }

        // REPLI daté (EDT-PUB-13-SUITE) : cache périmé servi (fetch
        // avorté/timeout 2 s) → la réponse dit l'ÂGE de son calendrier
        // (jour Paris du dernier fetch, champ cache_du) — jamais de
        // fraîcheur survendue.
        if ((bool) ($donnees['stale'] ?? false)) {
            $cacheDu = trim((string) ($donnees['cache_du'] ?? ''));
            if ($cacheDu !== '') {
                // La parenthèse se colle à la dernière phrase factuelle
                // (« … reprise le 09/11 (calendrier du 02/11). Source… »).
                $ligne = rtrim($ligne, '.') . " (calendrier du {$cacheDu}).";
            }
        }

        return $ligne . ' Source : edt-ulco.fr.';
    }

    // -----------------------------------------------------------------
    //  Gabarit stock — disponibilité d'un produit cafétéria (STOCK-PUB-14)
    // -----------------------------------------------------------------

    /**
     * Réponse gabarit à une question de stock d'un produit précis
     * (« il vous reste des Kinder Bueno ? »), ou null (question
     * non-stock, nom non plausible, base injoignable) → voie classique.
     * Résolution par le livre de stock UNIQUE (AssistantOutils →
     * StockBook), garde de fraîcheur identique à la voie admin.
     * 2 produits MAX par question (sinon invitation à demander un par
     * un), aucun candidat « produits proches » côté public.
     */
    public static function stock(string $question): ?string
    {
        $produitBrut = self::extraireProduit($question);
        if ($produitBrut === null) {
            return null;
        }

        $segments = array_values(array_filter(
            array_map(
                static fn (string $s): string => trim((string) preg_replace(
                    "/^(?:de\s+la|de\s+l|du|des|d|de)\s+/ui",
                    '',
                    trim($s)
                )),
                preg_split(self::MOTIF_SEPARATEUR_PRODUITS, $produitBrut) ?: []
            ),
            static fn (string $s): bool => $s !== ''
        ));
        // GENERIC-PUB-17 : un « produit » qui est en fait un mot GÉNÉRIQUE
        // (« des snacks ? », « à boire ») n'est pas un produit précis —
        // la question est générique, le gabarit liste la sélection.
        foreach ($segments as $segment) {
            if (self::produitGenerique($segment)) {
                return null;
            }
        }
        if (count($segments) > self::PRODUITS_MAX) {
            return 'Pose ta question produit par produit (un ou deux à la fois) : je vérifie chaque stock un à un.';
        }

        $lignes = [];
        foreach ($segments as $segment) {
            $plausible = self::produitPlausible($segment);
            if ($plausible === null) {
                return null; // mot hors-domaine dans le nom → voie LLM
            }
            try {
                $donnees = AssistantOutils::stockProduit($plausible);
            } catch (\Throwable) {
                return null; // fail-soft : la voie LLM reprend
            }
            $lignes[] = self::formaterStock($donnees, $plausible);
        }

        return implode(' ', $lignes);
    }

    /**
     * Premier motif stock qui matche → nom produit extrait (tel que
     * saisi, suffixes « en stock » retirés), sinon null. Portage de
     * detecter_question_stock (scripts/assistant.py) + troncature
     * publique des fins de phrase AVANT la validation : la question
     * réelle « …des Kinder Bueno en stock PARCE QUE j'aimerais… » doit
     * être validée sur « Kinder Bueno en stock » (sinon le mot « stock »
     * de la subordonnée disqualifie le nom — MOTS_NON_PRODUITS).
     */
    private static function extraireProduit(string $question): ?string
    {
        $q = str_replace('’', "'", $question);
        foreach (self::MOTIFS_STOCK_PRODUIT as $motif) {
            $motif = str_replace('%DET%', self::DETERMINANTS_PRODUIT, $motif);
            if (preg_match($motif, $q, $m) === 1) {
                // Fin de phrase / subordonnée retirée, PUIS validation.
                $morceaux = preg_split('/(?<=[.!?;])\s+|\s+(?:parce que|mais|alors|car|sinon|stp|svp)\s+/ui', $m['produit']) ?: [];
                $produit = trim((string) ($morceaux[0] ?? ''));
                if ($produit === '') {
                    continue;
                }
                $produit = self::produitPlausible($produit);
                if ($produit !== null) {
                    return $produit;
                }
            }
        }

        return null;
    }

    /**
     * Nettoie et valide un nom de produit extrait (mêmes règles que le
     * fast-path stock du CLI) : ni vide, ni trop long, au moins une
     * lettre, aucun mot signalant une question NON-produit.
     */
    private static function produitPlausible(string $produitBrut): ?string
    {
        $produit = trim((string) preg_replace('/\s+/u', ' ', $produitBrut));
        $produit = trim((string) preg_replace('/[\s?!.…]+\s*$/u', '', $produit));
        $produit = trim((string) preg_replace('/\s+en\s+stock$/ui', '', $produit));

        if ($produit === '' || mb_strlen($produit) > 255 || preg_match('/\p{L}/u', $produit) !== 1) {
            return null;
        }

        if (preg_match_all("/[a-zà-öø-ÿœæ0-9']+/u", mb_strtolower($produit, 'UTF-8'), $mots) !== false) {
            foreach ($mots[0] as $mot) {
                if (in_array($mot, self::MOTS_NON_PRODUITS, true)) {
                    return null;
                }
            }
        }

        return $produit;
    }

    /**
     * Met en forme la réponse stock pour le visiteur : chiffre sourcé du
     * livre de stock, dernier comptage, garde de fraîcheur IDENTIQUE à
     * la voie admin (« Comptage ancien (X j), fiabilité limitée. »).
     * Divergences volontaires côté public (arbitrage STOCK-PUB-14) :
     * stock théorique ≤ 0 → « épuisé pour l'instant » (jamais le détail
     * « survente/anomalie » interne du CLI) ; produit introuvable →
     * AUCUN candidat « produits proches » (pas de liste d'inventaire).
     *
     * @param array<string, mixed> $donnees Réponse AssistantOutils::stockProduit().
     */
    private static function formaterStock(array $donnees, string $nomDemande): string
    {
        if (($donnees['trouve'] ?? false) !== true) {
            $nom = (string) ($donnees['nom_demande'] ?? $nomDemande);

            return "Je ne trouve pas « {$nom} » dans les produits de la cafétéria. Pose ta question produit par produit.";
        }

        $nom     = (string) ($donnees['cle_canonique'] ?? $nomDemande);
        $stock   = $donnees['stock'] ?? null;
        $comptage = (string) ($donnees['dernier_comptage'] ?? '');
        $suite   = $comptage !== '' ? " (dernier comptage : {$comptage})" : '';

        $avertissement = trim((string) ($donnees['avertissement'] ?? ''));
        $alerte = $avertissement !== ''
            ? ' ' . mb_strtoupper(mb_substr($avertissement, 0, 1)) . mb_substr($avertissement, 1) . '.'
            : '';

        if (is_numeric($stock) && (int) $stock > 0) {
            return "Stock de {$nom} : {$stock} unité(s){$suite}.{$alerte}";
        }

        // Stock théorique ≤ 0 (ou négatif = anomalie de comptage) : côté
        // public on dit simplement qu'il n'y en a plus, sans le détail
        // interne « survente » servi au CLI admin.
        return "Stock de {$nom} : épuisé pour l'instant{$suite}.{$alerte}";
    }

    // -----------------------------------------------------------------
    //  Gabarit produits — offre générique cafétéria (GENERIC-PUB-17)
    // -----------------------------------------------------------------

    /**
     * Réponse gabarit à une question GÉNÉRIQUE sur l'offre de la
     * cafétéria (« vous avez quoi à boire et à manger à la cafétéria ? »,
     * « koi vous avez comme snacks ? » — 5 preuves servies horaires par
     * erreur), ou null (question non générique, horaires explicites,
     * produit précis → gabarit stock, base injoignable) → voie classique.
     *
     * Priorité de routage (arbitrage GENERIC-PUB-17) : horaires
     * EXPLICITES > produits génériques — la question « à quelle heure… »
     * reste sur le fast-path horaires ; un produit PRÉCIS reste sur le
     * gabarit stock. Données : AssistantOutils::stockSelection (livre
     * UNIQUE, MAX ~10 familles, nom + disponibilité, AUCUN prix).
     */
    public static function produits(string $question): ?string
    {
        if (!self::questionProduitsGenerique($question)) {
            return null;
        }

        try {
            $selection = AssistantOutils::stockSelection();
        } catch (\Throwable) {
            return null; // fail-soft : la voie LLM reprend
        }
        if ($selection['familles'] === []) {
            // Livre vide/injoignable : réponse honnête FIXE, JAMAIS un
            // repli LLM qui inventerait une liste de produits.
            return "Je n'arrive pas à consulter la liste des produits de la cafétéria pour l'instant, repasse dans un instant.";
        }

        return self::formaterProduits($selection);
    }

    /**
     * Question GÉNÉRIQUE sur l'offre (marqueurs « à boire/à manger/
     * snacks/encas/nourriture/boissons »), SANS horaires explicites
     * (priorité fast-path horaires) et SANS produit précis extractible
     * par les motifs stock (le gabarit stock garde « il vous reste du
     * coca à boire ? » — le produit précis prime sur le générique).
     */
    private static function questionProduitsGenerique(string $question): bool
    {
        if (self::questionHoraires($question)) {
            return false;
        }
        $norm = str_replace('’', "'", PublicAssistant::sansAccents($question));
        if (preg_match(self::MOTIF_PRODUITS_GENERIQUES, $norm) !== 1) {
            return false;
        }

        // Produit PRÉCIS extractible ET non générique → gabarit stock
        // (« il vous reste du coca à boire ? » garde sa réponse produit).
        $extrait = self::extraireProduit($question);

        return $extrait === null || self::produitGenerique($extrait);
    }

    /**
     * La question porte EXPLICITEMENT sur les horaires (un mot du
     * vocabulaire horaires, fautes tolérées) ? Prioritaire sur les
     * gabarits EDT et produits — les questions « à quelle heure »
     * rejoignent le fast-path horaires de la FAQ.
     */
    private static function questionHoraires(string $question): bool
    {
        $norm = str_replace('’', "'", PublicAssistant::sansAccents($question));
        // Mots SANS apostrophe : « s'ouvre » → « ouvre », « d'ouverture »
        // → « ouverture » (les mots horaires n'ont pas d'apostrophe).
        if (preg_match_all('/[a-z0-9]+/u', $norm, $mots) !== false) {
            foreach ($mots[0] as $mot) {
                if (in_array($mot, self::MOTS_HORAIRES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Un segment de question est-il purement GÉNÉRIQUE (« snacks »,
     * « à boire », « quoi boire ») et sans produit précis ? TOUT mot du
     * segment doit être générique/article : « coca à boire » contient
     * « coca » → pas générique (gabarit stock). (Déférence du gabarit
     * stock vers le gabarit liste, GENERIC-PUB-17.)
     */
    private static function produitGenerique(string $segment): bool
    {
        $norm = str_replace('’', "'", PublicAssistant::sansAccents($segment));
        // Garde sur ÉCHEC d'extraction uniquement : un segment peut être
        // MULTI-MOTS (« quoi boire ») — c'est la boucle qui vérifie ensuite
        // que CHAQUE mot est générique (compter les mots rejetait « quoi
        // boire » d'office → « produit introuvable » au lieu de la liste).
        if (preg_match_all('/[a-z0-9]+/u', $norm, $mots) === false || $mots[0] === []) {
            return false;
        }
        foreach ($mots[0] as $mot) {
            if (!in_array($mot, self::MOTS_GENERIQUES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Met en forme la sélection publique : familles disponibles (nom +
     * unités restantes, MAX ~10), invitation à demander produit par
     * produit pour la dispo exacte, dernier comptage cité, garde de
     * fraîcheur IDENTIQUE à la voie admin dès 30 j. Aucun prix ni coût
     * (arbitrage GENERIC-PUB-17 : prix de vente non exposés ailleurs
     * publiquement → rien).
     *
     * Honnêteté familles (mission 03/11) : une famille multi-clés est
     * EXPLICITE (« Bueno (126 unités, 2 variétés) » = Bueno + Bueno
     * white) pour ne plus contredire la clé exacte servie par la voie
     * spécifique (« Stock de Bueno : 17 unité(s)… »), et porte SA
     * garde de fraîcheur (clé la plus ancienne de la famille) — les
     * champs « cles »/« fraicheur_jours » absents (ancien format)
     * reproduisent la mise en forme d'avant, exacte au caractère.
     *
     * @param array<string, mixed> $selection Réponse AssistantOutils::stockSelection().
     */
    private static function formaterProduits(array $selection): string
    {
        $noms = [];
        foreach ((array) ($selection['familles'] ?? []) as $famille) {
            $nom = trim((string) ($famille['nom'] ?? ''));
            if ($nom !== '') {
                $stock = (int) ($famille['stock'] ?? 0);
                $cles = max(1, (int) ($famille['cles'] ?? 1));
                $fragments = $cles > 1 ? [$stock . ' unités', $cles . ' variétés'] : [$stock];
                $fraicheur = $famille['fraicheur_jours'] ?? null;
                if (is_int($fraicheur) && $fraicheur > AssistantOutils::FRAICHEUR_SEUIL_JOURS) {
                    $fragments[] = 'comptage ancien (' . $fraicheur . ' j)';
                }
                $noms[] = $nom . ' (' . implode(', ', $fragments) . ')';
            }
        }

        $ligne = 'En ce moment à la cafétéria (sélection) : ' . implode(', ', $noms)
            . '. Demande-moi produit par produit pour savoir si ton préféré est dispo.';

        $comptage = (string) ($selection['dernier_comptage'] ?? '');
        if ($comptage !== '') {
            $ligne .= " Dernier comptage : {$comptage}.";
        }
        $avertissement = trim((string) ($selection['avertissement'] ?? ''));
        if ($avertissement !== '') {
            $ligne .= ' ' . mb_strtoupper(mb_substr($avertissement, 0, 1)) . mb_substr($avertissement, 1) . '.';
        }

        return $ligne;
    }
}
