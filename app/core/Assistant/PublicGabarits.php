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
        'soiree', 'soirees', 'soirée', 'soirées', 'sortie', 'sorties',
        'afterwork', 'afterworks', 'bbq', 'bowling', 'adhesion', 'adhésion',
        'cotisation', 'cotisations', 'inscription', 'inscriptions', 'compte',
        'jeu', 'jeux', 'contact', 'mail', 'horaire', 'horaires',
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
}
