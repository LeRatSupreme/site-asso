<?php

declare(strict_types=1);

namespace App\Core\Assistant;

/**
 * INTENTIONS de l'assistant public — point d'assemblage UNIQUE du repli
 * du fast-path (FAST-07 révisé + FAQ-SCORE-20, selfreview 06/11 09h,
 * 3e rapport d'affilée) : « le fast-path n'a le droit de répondre QUE
 * si l'intention est servie ; sinon deux options honnêtes — le LLM
 * local ou un aveu — JAMAIS le voisin lexical ».
 *
 * Bug corrigé à la racine (bug « cafét* » fourre-tout, preuves
 * selfreview 04/11 → 06/11) : l'entrée cafétéria de la FAQ attrapait
 * par sous-chaîne TOUTE question contenant « cafét » — « payer par
 * carte à la cafétéria ? » était servie par les HORAIRES en 86 ms
 * (réponse assurée hors sujet, 06/11 09h08), « prochain
 * réapprovisionnement de la cafétéria ? » pareil (3e preuve), « combien
 * ça coûte les boissons ? » partait sur les STOCKS (05/11 02h). La
 * vraie frontière n'est pas la forme de la question mais l'EXISTENCE de
 * l'intention dans la liste servie : sans elle, le fast-path absorbe.
 *
 * PublicAssistant::executer() consulte intentionNonServie() AVANT les
 * gabarits ET la FAQ : une intention non servie fait replier la
 * question sur la voie LLM LOCALE (llama-server du VPS — directive
 * 29/10 : 100 % local, jamais de fournisseur externe) ou, si le serveur
 * est indisponible, sur le 503 honnête du contrat. L'intention détectée
 * est journalisée dans la trace publique (champ additif « intention »)
 * — le taux de déroutage devient mesurable en KPI sans relecture
 * humaine des questions.
 *
 * Les intentions SERVIES sont portées par la FAQ elle-même (clé
 * « intention » de chaque entrée d'app/config/assistant_faq.php :
 * identite, adresse, adhesion, evenements, cafeteria, jeux, contact,
 * site, politesse, merci, salutation) et par les gabarits publics
 * (PublicGabarits : edt, stock, produits). « participer/bénévolat » est
 * SERVI par l'entrée adhésion (mapping FAST-07, selfreview 06/11 02h).
 * Une NOUVELLE intention non couverte = un marqueur ci-dessous ; une
 * intention qui DEVIENT couverte (donnée saisie, gabarit écrit) = en
 * retirer le marqueur — ce fichier est le seul point de vérité.
 */
final class AssistantIntentions
{
    /**
     * Radicaux de MOTS (préfixes : « reappro » attrape
     * « réapprovisionnement », « cout » attrape « coûte/coûtent »)
     * signalant une intention NON SERVIE par le fast-path public.
     * Comparés mot par mot au texte normalisé
     * (PublicAssistant::normaliserTexte : minuscule, sans accents,
     * ponctuation → espaces).
     *
     * @var array<string, list<string>> intention => radicaux
     */
    private const MOTS_NON_SERVIS = [
        // Moyen de paiement — aucune donnée publique n'existe : question
        // réelle du 06/11 09h08 servie hors sujet par les horaires.
        'paiement' => [
            'paiement', 'payement', 'paie', 'payer', 'paya', // payer/payant/paieraient…
            'espece', 'cheque', 'cb', 'terminal',
        ],
        // Prix / tarifs — TARIFS-11 : la donnée n'existe pas encore, la
        // question était déroutée vers les STOCKS (gabarit produits,
        // preuve 05/11 02h : « cOmbIeN çA cOûtE lEs bOisSoNs… »).
        'tarifs' => ['prix', 'tarif', 'cout'],
        // Calendrier de réapprovisionnement — QA-01 : la question
        // naturelle publique était servie horaires (preuves 04/11, 05/11,
        // 06/11) alors que la donnée n'existe que côté admin.
        'reappro' => ['reappro', 'ravitaillement'],
        // Allergènes / régimes — aucune donnée publique.
        'allergenes' => [
            'allergene', 'allergique', 'vegan', 'vegetarien', 'vegetalien',
            'halal', 'kacher', 'gluten', 'lactose',
        ],
    ];

    /**
     * PHRASES (sous-chaînes du texte normalisé) signalant une intention
     * non servie — les tournures multi-mots qu'un radical de mot seul ne
     * couvre pas. Volontairement PRÉCISES (« la carte » est exclu : en
     * contexte restauration c'est le menu, une question produits).
     *
     * @var array<string, list<string>> intention => phrases
     */
    private const PHRASES_NON_SERVIES = [
        'paiement' => [
            'par carte', 'en carte', 'carte bancaire', 'carte bleue',
            'sans contact',
        ],
    ];

    /**
     * Intention NON SERVIE exprimée par la question, ou null si aucune
     * intention non couverte n'y apparaît (fast-path autorisé). Plusieurs
     * intentions peuvent matcher : la première du registre gagne (ordre
     * de déclaration = priorité de journalisation).
     *
     * Entrée : la question BRUTE (normalisation partagée faite ici, une
     * seule règle pour tout le site).
     */
    public static function intentionNonServie(string $question): ?string
    {
        $normalisee = PublicAssistant::normaliserTexte($question);
        if ($normalisee === '') {
            return null;
        }

        foreach (self::MOTS_NON_SERVIS as $intention => $radicaux) {
            foreach (self::mots($normalisee) as $mot) {
                foreach ($radicaux as $radical) {
                    if (str_starts_with($mot, $radical)) {
                        return $intention;
                    }
                }
            }
        }

        foreach (self::PHRASES_NON_SERVIES as $intention => $phrases) {
            foreach ($phrases as $phrase) {
                if (mb_strpos($normalisee, $phrase) !== false) {
                    return $intention;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> mots (lettres/chiffres) du texte normalisé
     */
    private static function mots(string $normalisee): array
    {
        preg_match_all('/[a-z0-9]+/', $normalisee, $m);

        return $m[0];
    }
}
