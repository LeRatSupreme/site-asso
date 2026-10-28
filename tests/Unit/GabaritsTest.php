<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Assistant\FastPath;
use App\Core\Assistant\Gabarits;
use PHPUnit\Framework\TestCase;

/**
 * Gabarits de pré-réponse (steer V1+) : auto-génération par templatysation
 * de la dernière réponse ok du journal, remplissage sur données LIVE,
 * interdiction des gabarits troués, fenêtres de dates recalculées J / J+3.
 */
final class GabaritsTest extends TestCase
{
    private const RESULTAT_BUENO = [
        'outil' => 'stock_produit', 'nom_demande' => 'Bueno', 'trouve' => true,
        'cle_canonique' => 'Bueno', 'stock' => 24,
        'dernier_comptage' => '2026-10-13 17:21:35', 'resolution' => 'exact', 'candidats' => [],
    ];

    public function test_generation_templatysante_depuis_le_journal(): void
    {
        $genere = Gabarits::generer(
            'Il vous reste 24 unités de Bueno en stock. Dernier comptage : 2026-10-13.',
            self::RESULTAT_BUENO
        );
        self::assertNotNull($genere);
        self::assertStringContainsString('{stock}', $genere['gabarit']);
        self::assertStringContainsString('{cle_canonique}', $genere['gabarit']);
        self::assertStringContainsString('{dernier_comptage}', $genere['gabarit']);
        self::assertSame('stock', $genere['mapping']['stock']);
        self::assertSame('cle_canonique', $genere['mapping']['cle_canonique']);
        self::assertStringNotContainsString('24', $genere['gabarit'], 'aucune valeur figée ne doit rester');
    }

    public function test_generation_avec_montant_francais(): void
    {
        $genere = Gabarits::generer(
            'Les ventes ont totalisé 379,05 € sur la période.',
            ['total_ventes_eur' => 379.05]
        );
        self::assertNotNull($genere);
        self::assertStringContainsString('{total_ventes_eur}', $genere['gabarit']);
    }

    public function test_generation_sans_rien_a_templatyser(): void
    {
        self::assertNull(Gabarits::generer('Réponse sans aucun chiffre issu de l\'outil.', ['outil' => 'x']));
    }

    public function test_remplissage_sur_donnees_live(): void
    {
        $live = self::RESULTAT_BUENO;
        $live['stock'] = 31;
        $rempli = Gabarits::remplir(
            'Il vous reste {stock} unités de {cle_canonique}. Dernier comptage : {dernier_comptage}.',
            ['stock' => 'stock', 'cle_canonique' => 'cle_canonique', 'dernier_comptage' => 'dernier_comptage'],
            [['donnees' => $live]]
        );
        self::assertSame('Il vous reste 31 unités de Bueno. Dernier comptage : 2026-10-13 17:21:35.', $rempli);
    }

    public function test_remplissage_formate_les_decimaux_a_la_francaise(): void
    {
        $rempli = Gabarits::remplir('CA : {total} €', ['total' => 'total_ventes_eur'], [['donnees' => ['total_ventes_eur' => 379.05]]]);
        self::assertSame('CA : 379,05 €', $rempli);
    }

    public function test_jamais_de_gabarit_troue(): void
    {
        $mapping = ['cle_canonique' => 'cle_canonique'];
        self::assertNull(Gabarits::remplir('{stock} restants', $mapping, [['donnees' => self::RESULTAT_BUENO]]), 'placeholder inconnu du mapping');
        self::assertNull(Gabarits::remplir('{stock}', ['stock' => 'chemin.inexistant'], [['donnees' => self::RESULTAT_BUENO]]), 'chemin introuvable dans le live');
        self::assertNull(Gabarits::remplir('texte sans placeholder', ['stock' => 'stock'], [['donnees' => self::RESULTAT_BUENO]]));
    }

    public function test_placeholders_exotiques_replient_sur_la_voie_llm(): void
    {
        // Durcissement dev-secu : la détection est élargie à TOUT token {…}
        // — une forme exotique non EXACTEMENT couverte par le mapping n'est
        // jamais affichée verbatim : remplir() renvoie null (repli LLM),
        // même si la clé existe en minuscules dans le mapping.
        self::assertNull(
            Gabarits::remplir('{Stock} restants', ['stock' => 'stock'], [['donnees' => self::RESULTAT_BUENO]]),
            'placeholder exotique (casse mixte) : repli LLM, jamais verbatim'
        );
        self::assertNull(
            Gabarits::remplir('{a b} restants', ['stock' => 'stock'], [['donnees' => self::RESULTAT_BUENO]]),
            'placeholder exotique (espace) : repli LLM, jamais verbatim'
        );
    }

    public function test_validation_6_fenetres_differentes_entre_j_et_j_plus_3(): void
    {
        $routage = [['outil' => 'ventes_periode', 'params' => [
            'debut' => ['relatif' => 'semaine_courante.debut'],
            'fin'   => ['relatif' => 'semaine_courante.fin'],
        ]]];
        putenv('ASSISTANT_OUTILS_URL=https://exemple.test');

        $dimancheJ = new \DateTimeImmutable('2026-10-25 09:00', new \DateTimeZone('Europe/Paris'));
        $mercrediJ3 = new \DateTimeImmutable('2026-10-28 09:00', new \DateTimeZone('Europe/Paris'));

        $urlsJ = FastPath::urlsOutils($routage, $dimancheJ);
        $urlsJ3 = FastPath::urlsOutils($routage, $mercrediJ3);

        self::assertNotNull($urlsJ);
        self::assertNotNull($urlsJ3);
        self::assertStringContainsString('debut=2026-10-19', $urlsJ[0], 'J (dimanche 25/10) : lundi de SA semaine');
        self::assertStringContainsString('fin=2026-10-25', $urlsJ[0]);
        self::assertStringContainsString('debut=2026-10-26', $urlsJ3[0], 'J+3 (mercredi 28/10) : lundi suivant — fenêtre DIFFÉRENTE et correcte');
        self::assertStringContainsString('fin=2026-10-28', $urlsJ3[0]);
        self::assertNotSame($urlsJ[0], $urlsJ3[0]);

        putenv('ASSISTANT_OUTILS_URL');
    }
}
