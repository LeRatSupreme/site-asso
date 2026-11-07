<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Core\Assistant\PublicAssistant;
use App\Core\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * REPLI du fast-path + champ « intention » de la trace publique
 * (FAST-07 révisé + FAQ-SCORE-20, selfreview 06/11 09h — 3e rapport
 * d'affilée).
 *
 * Tests d'épinglage = les QUESTIONS EXACTES offertes par les selfreview
 * (rapports 05/11 13h paire contrastée, 06/11 02h participer, 06/11 09h
 * paiement/réappro, 05/11 02h prix) : chaque dérive prouvée en prod
 * devient un test rejouable.
 *
 * Aucun LLM réel : l'URL pointe sur un port fermé — le repli se lit
 * donc comme « la question part sur la voie LLM » (503 du contrat en
 * test, génération locale en prod ; jamais une réponse fast-path).
 */
final class AssistantFastPathRepliTest extends TestCase
{
    private string $tmp;

    /** Routeur du faux llama-server démarré par le dernier test (nettoyé
     *  en tearDown : php -S RELIT le fichier à CHAQUE requête, il doit
     *  survivre tant que le serveur tourne). */
    private string $routeurFauxLlm = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/aeic-assistant-repli-' . getmypid() . '-' . uniqid('', true);
        @mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        if ($this->routeurFauxLlm !== '') {
            @unlink($this->routeurFauxLlm);
            $this->routeurFauxLlm = '';
        }
        foreach (glob($this->tmp . '/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($this->tmp);
    }

    /** Service branché sur un « llama-server down » (port fermé). */
    private function service(): PublicAssistant
    {
        return new PublicAssistant(
            new RateLimiter($this->tmp),
            'http://127.0.0.1:1/v1/chat/completions',
            $this->tmp . '/assistant_public.jsonl',
            $this->tmp . '/assistant_public'
        );
    }

    /** @return list<array<string,mixed>> lignes du journal public */
    private function journal(): array
    {
        $brut = (string) @file_get_contents($this->tmp . '/assistant_public.jsonl');
        $lignes = array_values(array_filter(explode("\n", $brut)));

        return array_map(static fn (string $l): array => (array) json_decode($l, true), $lignes);
    }

    /**
     * Relit app/config/assistant_faq.php et renvoie la réponse de l'entrée
     * portant ce déclencheur (déclencheurs stockés déjà normalisés).
     */
    private static function reponseFaqPour(string $declencheur): string
    {
        /** @var list<array{declencheurs: list<string>, reponse: string}> $faq */
        $faq = require AEIC_ROOT . '/app/config/assistant_faq.php';
        foreach ($faq as $entree) {
            if (in_array($declencheur, $entree['declencheurs'], true)) {
                return $entree['reponse'];
            }
        }

        self::fail('Déclencheur inconnu dans assistant_faq.php : ' . $declencheur);
    }

    // -------------------------------------------------------------
    //  FAQ-SCORE-20 — paire contrastée du 05/11 13h (épinglage offert)
    // -------------------------------------------------------------

    /**
     * Paire contrastée live 13:10 (RAPPORT_2026-11-05_13h §3-Q1/Q2),
     * même préambule « Bonjour, pourriez-vous… » : l'adresse restait
     * adresse par pur hasard (égalité 7=7 + ordre du fichier) et les
     * JEUX partaient sur l'ACCUEIL générique hors sujet (« bonjour »
     * 7 car. > « jeux » 4). Avec le score agrégé par intention + classe
     * conversationnelle : l'intention métier gagne, salutation ou pas.
     */
    public function test_paire_contrastee_bonjour_adresse_vs_jeux(): void
    {
        $s = $this->service();

        $r = $s->traiter('Bonjour, pourriez-vous me donner l\'adresse de l\'association s\'il vous plaît ?', null, '8.8.4.4');
        self::assertSame(200, $r['http']);
        self::assertSame(self::reponseFaqPour('adresse'), $r['reply'], 'adresse reste adresse — par intention, plus par égalité de longueur.');
        self::assertNotSame(self::reponseFaqPour('bonjour'), $r['reply']);

        $r = $s->traiter('Bonjour, pourriez-vous me dire quels sont les jeux disponibles à l\'association ?', null, '8.8.4.5');
        self::assertSame(200, $r['http']);
        self::assertSame(self::reponseFaqPour('jeux'), $r['reply'], 'jeux → JEUX (intention métier), plus l\'accueil générique.');
        self::assertNotSame(self::reponseFaqPour('bonjour'), $r['reply'], 'la salutation ne détourne plus la question polie.');

        // La trace porte l'intention ayant capturé chaque question.
        $lignes = $this->journal();
        self::assertSame('adresse', $lignes[0]['intention']);
        self::assertSame('jeux', $lignes[1]['intention']);
        self::assertSame('fastpath', $lignes[0]['mode']);
        self::assertSame('fastpath', $lignes[1]['mode']);
    }

    /** Sans intention métier, la classe conversationnelle garde la main. */
    public function test_salutation_seule_reste_serve(): void
    {
        $s = $this->service();

        $r = $s->traiter('Bonjour', null, '8.8.4.4');
        self::assertSame(200, $r['http']);
        self::assertSame(self::reponseFaqPour('bonjour'), $r['reply']);

        $r = $s->traiter('Comment ça va ?', null, '8.8.4.5');
        self::assertSame(200, $r['http']);
        self::assertSame(self::reponseFaqPour('comment ca va'), $r['reply']);

        $lignes = $this->journal();
        self::assertSame('salutation', $lignes[0]['intention']);
        self::assertSame('politesse', $lignes[1]['intention']);
    }

    // -------------------------------------------------------------
    //  FAST-07 — « participer aux activités » servi par l'adhésion
    // -------------------------------------------------------------

    /**
     * Question exacte du 06/11 02h (casse alternée + accents mélangés) :
     * l'intention « comment PARTICIPER » était servie par la liste du
     * PROGRAMME (dérive « activites » 9 car.) — elle est mappée à
     * l'adhésion (« comment s'inscrire »), selfreview 06/11 02h §7-2.
     */
    public function test_participer_aux_activites_repond_l_adhesion_pas_le_programme(): void
    {
        $s = $this->service();

        $r = $s->traiter('CoMmEnT jE pEuX pArTiCiPeR aUx AcTiViTeS dE lAsSoCiAtIoN ?', null, '8.8.4.4');
        self::assertSame(200, $r['http']);
        self::assertSame(self::reponseFaqPour('adhesion'), $r['reply'], 'participer → adhésion (comment s\'inscrire).');
        self::assertNotSame(self::reponseFaqPour('evenement'), $r['reply'], 'pas la liste du programme.');

        // Formulation propre : même verdict (l'épinglage ne tient pas à la casse).
        $r = $s->traiter('Comment je peux participer aux activités ?', null, '8.8.4.5');
        self::assertSame(self::reponseFaqPour('adhesion'), $r['reply']);

        // Bénévolat : même parcours (mapping FAST-07 du 06/11 02h).
        $r = $s->traiter('Je veux faire du bénévolat à l\'asso', null, '8.8.4.6');
        self::assertSame(self::reponseFaqPour('adhesion'), $r['reply']);

        $lignes = $this->journal();
        self::assertSame('adhesion', $lignes[0]['intention']);
        self::assertSame('adhesion', $lignes[1]['intention']);
        self::assertSame('adhesion', $lignes[2]['intention']);
    }

    // -------------------------------------------------------------
    //  REPLI — intention non servie → voie LLM, jamais le voisin lexical
    // -------------------------------------------------------------

    /**
     * Question exacte du 06/11 09h08 : « payer par carte à la cafétéria »
     * était servie par les HORAIRES en 86 ms (réponse assurée hors
     * sujet). Désormais : gabarits et FAQ sautés, la question part sur
     * la voie LLM LOCALE (ici down → 503 honnête du contrat), avec
     * l'intention détectée dans la trace (KPI déroutage).
     */
    public function test_payer_par_carte_replie_sur_le_llm_pas_les_horaires(): void
    {
        $s = $this->service();

        $r = $s->traiter('Pourriez-vous me dire s\'il est possible de payer par carte à la cafétéria s\'il vous plaît ?', null, '8.8.4.4');

        // Voie LLM (down en test) : 503 du contrat — JAMAIS les horaires.
        self::assertSame(503, $r['http']);
        self::assertNull($r['reply']);
        self::assertSame("L'assistant est indisponible, réessaie dans un instant.", $r['error']);

        $lignes = $this->journal();
        self::assertCount(2, $lignes, 'start + fin (LOG-02).');
        self::assertSame('start', $lignes[0]['phase']);
        self::assertSame('fin', $lignes[1]['phase']);
        self::assertSame('llm', $lignes[1]['mode'], 'repli : la voie LLM répond (pas de fastpath).');
        self::assertFalse($lignes[1]['faq']);
        self::assertSame('paiement', $lignes[1]['intention'], 'intention détectée journalisée — déroutage mesurable en KPI.');
        self::assertSame('paiement', $lignes[0]['intention'], 'l\'entrée start porte la même intention.');
        self::assertNotSame(self::reponseFaqPour('cafet'), (string) $lignes[1]['reply'], 'la réponse horaires ne sort plus sur une question paiement.');
    }

    /**
     * QA-01 : « C'est quand le prochain réapprovisionnement de la
     * cafétéria ? » — 3e preuve (04/11, 05/11, 06/11 09h) d'une question
     * servie horaires alors que l'intention réappro n'est pas couverte
     * côté public. Désormais : repli voie LLM + intention tracée.
     */
    public function test_reappro_de_la_cafeteria_replie_sur_le_llm_pas_les_horaires(): void
    {
        $s = $this->service();

        $r = $s->traiter('C\'est quand le prochain réapprovisionnement de la cafétéria ?', null, '8.8.4.4');

        self::assertSame(503, $r['http'], 'voie LLM (down en test), pas le fast-path horaires.');
        $lignes = $this->journal();
        self::assertSame('llm', $lignes[1]['mode']);
        self::assertSame('reappro', $lignes[1]['intention']);
        self::assertNotSame(self::reponseFaqPour('cafet'), (string) $lignes[1]['reply']);
    }

    /**
     * REPLI COMPLET, LLM local DISPONIBLE : un faux llama-server (sous-
     * processus php -S, réponse de complétion en boîte) répond à la
     * question paiement — 200, mode=llm, intention=paiement, réponse du
     * MODÈLE (pas des horaires). Épingle AUSSI le chemin de succès de la
     * voie LLM (signature conclure : regression attrapée en démo du
     * 06/11 — arg Gabarit décalé — invisible des tests « LLM down »).
     */
    public function test_repli_paiement_repondu_par_le_llm_local_disponible(): void
    {
        $port = self::portLibre();
        $serveur = self::demarrerFauxLlm($port);
        if ($serveur === null) {
            self::markTestSkipped('Impossible de démarrer le faux llama-server (php -S).');
        }

        try {
            $s = new PublicAssistant(
                new RateLimiter($this->tmp),
                "http://127.0.0.1:{$port}/v1/chat/completions",
                $this->tmp . '/assistant_public.jsonl',
                $this->tmp . '/assistant_public'
            );

            $r = $s->traiter('Pourriez-vous me dire s\'il est possible de payer par carte à la cafétéria s\'il vous plaît ?', null, '8.8.4.4');
        } finally {
            proc_terminate($serveur);
            proc_close($serveur);
        }

        self::assertSame(200, $r['http'], 'le LLM local répond (ici en boîte, en prod llama-server du VPS).');
        self::assertSame('Je ne sais pas si la cafétéria prend la carte : demande au bureau AEIC.', $r['reply'], 'réponse du MODÈLE (honnête), jamais les horaires.');

        $lignes = $this->journal();
        self::assertCount(2, $lignes, 'start + fin.');
        self::assertSame('llm', $lignes[1]['mode']);
        self::assertSame('paiement', $lignes[1]['intention']);
        self::assertFalse($lignes[1]['faq']);
        self::assertSame($lignes[0]['id'], $lignes[1]['id']);
    }

    /**
     * Preuve 05/11 02h : « cOmbIeN çA cOûtE lEs bOisSoNs aLa cAfEtErIa ? »
     * était déroutée vers les STOCKS (gabarit produits, TARIFS-11).
     * Désormais : l'intention tarifs (non servie — aucune donnée prix)
     * replie sur la voie LLM, gabarit produits compris.
     */
    public function test_prix_des_boissons_replie_sur_le_llm_pas_les_stocks(): void
    {
        $s = $this->service();

        $r = $s->traiter('cOmbIeN çA cOûtE lEs bOisSoNs aLa cAfEtErIa ?', null, '8.8.4.4');

        self::assertSame(503, $r['http'], 'voie LLM (down en test), pas le gabarit produits.');
        $lignes = $this->journal();
        self::assertSame('llm', $lignes[1]['mode'], 'ni fastpath ni gabarit : le routage stocks est bloqué.');
        self::assertSame('tarifs', $lignes[1]['intention']);
    }

    /** Port TCP local libre (bind éphémère puis libération). */
    private static function portLibre(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, "socket de test : {$errstr}");
        $nom = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($nom, (int) strrpos($nom, ':') + 1);
    }

    /**
     * Faux llama-server : sous-processus « php -S » servant UNE réponse de
     * complétion en boîte (aveu honnête du paiement) à tout POST — assez
     * pour le chemin succès de callLlm/extractReply, sans vrai modèle.
     *
     * @return resource|null process, null si le serveur ne démarre pas.
     */
    private function demarrerFauxLlm(int $port)
    {
        $reponse = json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Je ne sais pas si la cafétéria prend la carte : demande au bureau AEIC.'], 'finish_reason' => 'stop'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $routeur = tempnam(sys_get_temp_dir(), 'aeic-faux-llm-');
        file_put_contents($routeur, '<?php header("Content-Type: application/json"); echo ' . var_export($reponse, true) . ';');
        $this->routeurFauxLlm = $routeur;

        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $routeur],
            [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            @unlink($routeur);

            return null;
        }

        // Attente de mise en service (max ~2 s).
        for ($i = 0; $i < 40; $i++) {
            usleep(50_000);
            $sonde = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($sonde)) {
                fclose($sonde);

                return $proc; // le routeur est nettoyé en tearDown
            }
        }

        proc_terminate($proc);
        proc_close($proc);
        @unlink($routeur);
        $this->routeurFauxLlm = '';

        return null;
    }

    // -------------------------------------------------------------
    //  Champ « intention » — KPI de déroutage (livrable trace)
    // -------------------------------------------------------------

    /**
     * Le champ additif « intention » nomme TOUJOURS le chemin ayant
     * capté la question : clé « intention » de l'entrée FAQ, nom du
     * gabarit — « faq » booléen et « mode » ne suffisaient pas à dire
     * QUEL fastpath a répondu (selfreview 06/11 09h §3 traçabilité).
     */
    public function test_champ_intention_sur_les_trois_chemins(): void
    {
        $s = $this->service();
        $ips = ['8.8.1.1', '8.8.1.2', '8.8.1.3', '8.8.1.4'];

        $s->traiter('Quels sont les horaires de la cafétéria ?', null, $ips[0]);   // fastpath FAQ
        $s->traiter('kOi vOus avEz a bOire et a mAngEr a la cafEtErIa ?', null, $ips[1]); // gabarit produits
        $s->traiter('Quelle est la météo demain ?', null, $ips[2]);                // llm, intention inconnue
        $s->traiter('on peut payer par carte a la cafet ?', null, $ips[3]);        // llm après repli

        $lignes = $this->journal();
        self::assertCount(6, $lignes, '2 lignes directes + 1 start/fin météo + 1 start/fin paiement.');

        $faq = $lignes[0];
        self::assertSame('fastpath', $faq['mode']);
        self::assertSame('cafeteria', $faq['intention'], 'nom de l\'entrée FAQ capturante.');

        $gabarit = $lignes[1];
        self::assertSame('gabarit', $gabarit['mode']);
        self::assertSame('produits', $gabarit['intention'], 'nom du gabarit capturant.');

        $meteo = $lignes[3];
        self::assertSame('llm', $meteo['mode']);
        self::assertSame('', $meteo['intention'], 'aucune intention détectée : chaîne vide, champ toujours présent.');

        $paiement = $lignes[5];
        self::assertSame('llm', $paiement['mode']);
        self::assertSame('paiement', $paiement['intention'], 'repli : l\'intention non servie est journalisée.');
    }
}
