<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Core\Assistant\AssistantIntentions;
use PHPUnit\Framework\TestCase;

/**
 * Registre des intentions NON SERVIES (AssistantIntentions) — point de
 * vérité du repli du fast-path (FAST-07 révisé + FAQ-SCORE-20, selfreview
 * 06/11 09h) : une question exprimant une intention non couverte
 * (paiement, prix, réappro, allergènes) doit être signalée pour repli
 * LLM, sans jamais capturer par erreur une intention SERVIE (le piège
 * « pain » : le radical 'pai' seul attraperait le mot — volontairement
 * absent du registre).
 */
final class AssistantIntentionsTest extends TestCase
{
    /** Questions réelles des rapports selfreview (04/11 → 06/11) : chacune
     *  était déroutée par le fast-path lexical « cafét* » — elles doivent
     *  toutes être signalées non servies. */
    public function test_intentions_non_servies_detectees_questions_reelles(): void
    {
        // 06/11 09h Q1 : paiement par carte servie horaires en 86 ms.
        self::assertSame(
            'paiement',
            AssistantIntentions::intentionNonServie('Pourriez-vous me dire s\'il est possible de payer par carte à la cafétéria s\'il vous plaît ?')
        );
        // 06/11 09h Q2 / QA-01 : réappro servi horaires (3e preuve).
        self::assertSame(
            'reappro',
            AssistantIntentions::intentionNonServie('C\'est quand le prochain réapprovisionnement de la cafétéria ?')
        );
        // 05/11 02h : prix des boissons servi STOCKS (gabarit produits).
        self::assertSame(
            'tarifs',
            AssistantIntentions::intentionNonServie('cOmbIeN çA cOûtE lEs bOisSoNs aLa cAfEtErIa ?')
        );
        // Variantes naturelles des mêmes intentions.
        self::assertSame('tarifs', AssistantIntentions::intentionNonServie('Quel est le prix de l\'eau ?'));
        self::assertSame('tarifs', AssistantIntentions::intentionNonServie('Vos tarifs sont où ?'));
        self::assertSame('paiement', AssistantIntentions::intentionNonServie('Vous acceptez la carte bancaire à la cafet ?'));
        self::assertSame('paiement', AssistantIntentions::intentionNonServie('On peut payer en espèces ?'));
        self::assertSame('allergenes', AssistantIntentions::intentionNonServie('Vous avez des snacks sans gluten ?'));
        self::assertSame('reappro', AssistantIntentions::intentionNonServie('Quand arrive le ravitaillement ?'));
    }

    /** Intentions SERVIES (FAQ/gabarits) et hors-domaine : null — le
     *  fast-path reste autorisé, le repli ne doit pas dégrader les
     *  questions qu'il sert correctement. */
    public function test_questions_servies_ou_neutres_non_signalees(): void
    {
        foreach ([
            'Quels sont les horaires de la cafétéria ?',
            'il vous reste combien de Kinder Bueno ?',
            'kOi vOus avEz a bOire et a mAngEr a la cafEtErIa ?',
            // « participer » est SERVI (mappé adhésion, FAST-07 06/11 02h).
            'CoMmEnT jE pEuX pArTiCiPeR aUx AcTiViTeS dE lAsSoCiAtIoN ?',
            'Comment je peux participer aux activités ?',
            'Bonjour',
            'Quelle est la météo demain ?',
            'il y a cours cette semaine ?',
            'C\'est quoi l\'AEIC ?',
        ] as $question) {
            self::assertNull(
                AssistantIntentions::intentionNonServie($question),
                'ne doit PAS être signalée non servie : ' . $question
            );
        }
    }

    /** Piège du radical 'pai' : « pain » est un PRODUIT potentiel, pas un
     *  paiement — le registre ne retient que des radicaux sans collision
     *  ('paie', 'payer', 'paiement', 'payement'). */
    public function test_le_mot_pain_n_est_pas_du_paiement(): void
    {
        self::assertNull(AssistantIntentions::intentionNonServie('Vous avez du pain ?'));
        self::assertNull(AssistantIntentions::intentionNonServie('Il reste des pains au chocolat ?'));
        // …tandis que les vraies formes verbales restent détectées.
        self::assertSame('paiement', AssistantIntentions::intentionNonServie('On paie comment à la cafet ?'));
        self::assertSame('paiement', AssistantIntentions::intentionNonServie('Est-ce que je peux payer avec un chèque ?'));
    }
}
