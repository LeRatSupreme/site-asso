<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\SidebarOrder;
use App\Models\Model;
use App\Models\Setting;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Ordre persistant de la sidebar admin (SidebarOrder).
 *
 * Point d'assemblage unique de l'ordre du menu : apply() réordonne
 * l'arbre du layout selon le setting « admin_sidebar_order » (tri
 * stable, clés inconnues ignorées, fallback silencieux sur donnée
 * invalide), sanitize() normalise le payload du front, save()
 * persiste avec une garde 8 Ko.
 *
 * Isolation SANS base de données (pas de pdo_sqlite ici) :
 *  - apply() : le cache statique de Setting est semé par réflexion
 *    (précédéent : CaptchaSettingBoundsTest), Setting::get() ne
 *    touche alors jamais PDO ;
 *  - save()  : un faux PDO (instance sans constructeur, précédent
 *    GuardFakeProdPdo de TestDatabaseGuardTest) enregistre les
 *    écritures SQL au lieu de les exécuter.
 */
final class SidebarOrderTest extends TestCase
{
    /**
     * Sous-ensemble fidèle de l'arbre du layout admin : sections =>
     * entrées, une entrée étant un lien plat ('label' => '/chemin')
     * ou un sous-groupe ('Nom' => ['label' => '/chemin', ...]).
     */
    private const ARBRE = [
        'Accueil' => [
            'Tableau de bord' => '/admin',
        ],
        'Stock' => [
            'Produits' => [
                'Catalogue' => '/admin/produits',
            ],
            'Inventaire' => [
                'Comptage à l\'aveugle' => '/admin/compta/inventaire/comptage',
                'Inventaire tablette'   => '/admin/compta/inventaire-tablette',
            ],
            'Mouvements' => '/admin/compta/mouvements', // lien plat mêlé aux sous-groupes
        ],
        'Association' => [
            'Membres' => '/admin/membres',
            'Jeux'    => ['Wordle' => '/admin/jeux/wordle'],
        ],
    ];

    private FakeSettingsPdo $pdo;

    protected function setUp(): void
    {
        $this->pdo = FakeSettingsPdo::creer();
        Model::setTestPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        Setting::clearCache();
    }

    // ------------------------------------------------------------------
    //  apply() — lecture du setting + réordonnage
    // ------------------------------------------------------------------

    public function test_apply_sans_ordre_enregistre_rend_l_arbre_tel_quel(): void
    {
        // Setting absent du cache (Setting::get retombe sur '') : défaut.
        $this->semerCache(['site_name' => 'AEIC']);

        self::assertSame(self::ARBRE, SidebarOrder::apply(self::ARBRE));
        self::assertSame([], $this->pdo->ecritures, 'Aucune écriture en lecture.');
    }

    public function test_apply_ordre_par_defaut_accolades_rend_l_arbre_tel_quel(): void
    {
        $this->stockerOrdre('{}');

        self::assertSame(self::ARBRE, SidebarOrder::apply(self::ARBRE));
    }

    public function test_apply_reordonne_les_sections_selon_le_json(): void
    {
        $this->stockerOrdre('{"sections":["Association","Stock"]}');

        $attendu = [
            'Association' => self::ARBRE['Association'],
            'Stock'       => self::ARBRE['Stock'],
            'Accueil'     => self::ARBRE['Accueil'], // absent du JSON : après, ordre défaut
        ];

        self::assertSame($attendu, SidebarOrder::apply(self::ARBRE));
    }

    public function test_apply_section_absente_du_json_reste_apres_les_connues(): void
    {
        // Une seule section connue du JSON : elle passe devant, les
        // autres gardent l'ordre par défaut derrière (tri stable).
        $this->stockerOrdre('{"sections":["Association"]}');

        $attendu = [
            'Association' => self::ARBRE['Association'],
            'Accueil'     => self::ARBRE['Accueil'],
            'Stock'       => self::ARBRE['Stock'],
        ];

        self::assertSame($attendu, SidebarOrder::apply(self::ARBRE));
    }

    public function test_apply_reordonne_les_entrees_liens_plats_et_sous_groupes_meles(): void
    {
        // 'items' trie les entrées d'une section, liens plats ET
        // sous-groupes confondus (même niveau).
        $this->stockerOrdre('{"items":{"Stock":["Mouvements","Inventaire"]}}');

        $attendu = self::ARBRE;
        $attendu['Stock'] = [
            'Mouvements' => '/admin/compta/mouvements',
            'Inventaire' => self::ARBRE['Stock']['Inventaire'],
            'Produits'   => self::ARBRE['Stock']['Produits'], // absent du JSON : après
        ];

        self::assertSame($attendu, SidebarOrder::apply(self::ARBRE));
    }

    public function test_apply_reordonne_les_liens_d_un_sous_groupe_par_cle_composee(): void
    {
        // Clé composée « Section|SousGroupe » : ordre des liens à
        // l'intérieur du sous-groupe.
        $this->stockerOrdre('{"subgroups":{"Stock|Inventaire":["Inventaire tablette","Comptage à l\'aveugle"]}}');

        $attendu = self::ARBRE;
        $attendu['Stock']['Inventaire'] = [
            'Inventaire tablette'    => '/admin/compta/inventaire-tablette',
            'Comptage à l\'aveugle'  => '/admin/compta/inventaire/comptage',
        ];

        self::assertSame($attendu, SidebarOrder::apply(self::ARBRE));
    }

    public function test_apply_combine_sections_items_et_subgroups(): void
    {
        // Les trois niveaux s'appliquent ensemble : l'ordre complet
        // du menu est reconstruit depuis le seul JSON stocké.
        $this->stockerOrdre(json_encode([
            'sections'  => ['Stock', 'Accueil'],
            'items'     => ['Stock' => ['Mouvements', 'Inventaire'], 'Accueil' => ['Tableau de bord']],
            'subgroups' => ['Stock|Inventaire' => ['Inventaire tablette', 'Comptage à l\'aveugle']],
        ], JSON_UNESCAPED_UNICODE));

        $attendu = [
            'Stock' => [
                'Mouvements' => '/admin/compta/mouvements',
                'Inventaire' => [
                    'Inventaire tablette'   => '/admin/compta/inventaire-tablette',
                    'Comptage à l\'aveugle' => '/admin/compta/inventaire/comptage',
                ],
                'Produits' => self::ARBRE['Stock']['Produits'],
            ],
            'Accueil' => [
                'Tableau de bord' => '/admin',
            ],
            'Association' => self::ARBRE['Association'], // hors JSON : après, défaut
        ];

        $resultat = SidebarOrder::apply(self::ARBRE);

        self::assertSame($attendu, $resultat);
        self::assertSame($resultat, SidebarOrder::apply($resultat), 'apply() est idempotent.');
    }

    public function test_apply_ignore_les_cles_inconnues_du_json(): void
    {
        // Section fantôme, entrée fantôme d'une section réelle,
        // sous-groupe fantôme : rien n'est réordonné ni ajouté.
        $this->stockerOrdre(json_encode([
            'sections'  => ['Section fantôme'],
            'items'     => ['Stock' => ['Entrée fantôme'], 'Section fantôme' => ['X']],
            'subgroups' => ['Stock|Fantôme' => ['Y'], 'Fantôme|Inventaire' => ['Z']],
        ], JSON_UNESCAPED_UNICODE));

        $resultat = SidebarOrder::apply(self::ARBRE);

        self::assertSame(self::ARBRE, $resultat, 'Aucune clé du JSON inconnue ne doit bouger l\'arbre.');
        self::assertSame(array_keys(self::ARBRE), array_keys($resultat), 'Aucune clé ajoutée.');
    }

    /**
     * @dataProvider fournisseurJsonInvalide
     */
    public function test_apply_json_invalide_rend_l_arbre_tel_quel(string $stocke): void
    {
        $this->stockerOrdre($stocke);

        self::assertSame(self::ARBRE, SidebarOrder::apply(self::ARBRE));
    }

    /**
     * @return list<list<string>>
     */
    public static function fournisseurJsonInvalide(): array
    {
        return [
            ['{'],                        // JSON tronqué
            ['{"sections":'],             // JSON tronqué (début valide)
            ['null'],                     // JSON valide mais pas un objet
            ['42'],
            ['"une chaîne"'],
            ['[]'],                       // tableau vide : aucun champ connu
            ['{"sections":"pas une liste"}'], // type inattendu pour un champ
            ['{"items":{"Stock":"texte"}}'],  // items[Stock] pas une liste
        ];
    }

    public function test_apply_json_trop_volumineux_est_ignore(): void
    {
        // JSON VALIDE de plus de 8 KiO contenant un réordonnage réel :
        // si la garde de taille sautait, les sections bougeraient.
        $json = json_encode([
            'sections' => ['Association', 'Stock'],
            'items'    => ['Stock' => [str_repeat('a', SidebarOrder::MAX_JSON_BYTES)]],
        ], JSON_UNESCAPED_UNICODE);
        self::assertGreaterThan(SidebarOrder::MAX_JSON_BYTES, strlen($json));

        $this->stockerOrdre($json);

        self::assertSame(self::ARBRE, SidebarOrder::apply(self::ARBRE));
    }

    public function test_apply_json_de_exactement_8_kio_est_applique(): void
    {
        // La garde est « strictement plus de » MAX_JSON_BYTES : un
        // JSON qui fait PILE 8192 octets s'applique (le remplissage
        // est un label inconnu, ignoré par le tri).
        $prefix = '{"sections":["Association","Stock"],"items":{"Stock":["';
        $suffix = '"]}}';
        $json = $prefix . str_repeat('a', SidebarOrder::MAX_JSON_BYTES - strlen($prefix) - strlen($suffix)) . $suffix;
        self::assertSame(SidebarOrder::MAX_JSON_BYTES, strlen($json));

        $this->stockerOrdre($json);

        $attendu = [
            'Association' => self::ARBRE['Association'],
            'Stock'       => self::ARBRE['Stock'],
            'Accueil'     => self::ARBRE['Accueil'],
        ];

        self::assertSame($attendu, SidebarOrder::apply(self::ARBRE));
    }

    // ------------------------------------------------------------------
    //  sanitize() — normalisation du payload du front
    // ------------------------------------------------------------------

    public function test_sanitize_rend_un_payload_deja_propre_identique(): void
    {
        $propre = [
            'sections'  => ['Stock', 'Accueil'],
            'items'     => ['Stock' => ['Mouvements', 'Inventaire'], 'Accueil' => ['Tableau de bord']],
            'subgroups' => ['Stock|Inventaire' => ['Inventaire tablette', 'Comptage à l\'aveugle']],
        ];

        self::assertSame($propre, SidebarOrder::sanitize($propre));
    }

    public function test_sanitize_trimme_et_rejette_les_chaines_vides(): void
    {
        $nettoye = SidebarOrder::sanitize([
            'sections'  => [' Stock ', 'Accueil', '', '   '],
            'items'     => [' Stock ' => [' Inventaire ', 'Produits', '']],
            'subgroups' => [' Stock|Inventaire ' => [' À l\'aveugle '], '' => ['X']],
        ]);

        self::assertSame([
            'sections'  => ['Stock', 'Accueil'],
            'items'     => ['Stock' => ['Inventaire', 'Produits']],
            'subgroups' => ['Stock|Inventaire' => ['À l\'aveugle']], // clé trimmée, clé vide rejetée
        ], $nettoye);
    }

    public function test_sanitize_dedoublonne_en_conservant_la_premiere_occurrence(): void
    {
        $nettoye = SidebarOrder::sanitize([
            'sections'  => ['Accueil', 'Stock', 'Accueil', 'Accueil', 'Stock'],
            'items'     => ['Stock' => ['Mouvements', 'Inventaire', 'Mouvements']],
            'subgroups' => ['Stock|Inventaire' => ['A', 'B', 'A', 'A']],
        ]);

        self::assertSame([
            'sections'  => ['Accueil', 'Stock'],
            'items'     => ['Stock' => ['Mouvements', 'Inventaire']],
            'subgroups' => ['Stock|Inventaire' => ['A', 'B']],
        ], $nettoye);
    }

    public function test_sanitize_rejette_les_types_invalides(): void
    {
        $nettoye = SidebarOrder::sanitize([
            'sections'  => [42, true, null, 3.14, ['nested'], 'OK'], // seul 'OK' survit
            'items'     => [
                'Stock'     => 'pas une liste',          // valeur non liste : rejetée
                'Autre'     => [42, 'Produits', null],   // seules les chaînes survivent
                7           => ['X'],                    // clé entière coercée en chaîne
            ],
            'subgroups' => ['Stock|Fantôme' => []],      // liste vide : rejetée
        ]);

        self::assertSame([
            'sections'  => ['OK'],
            'items'     => ['Autre' => ['Produits'], '7' => ['X']],
        ], $nettoye);
    }

    /**
     * @dataProvider fournisseurPayloadInutilisable
     */
    public function test_sanitize_payload_inutilisable_rend_un_tableau_vide(mixed $payload): void
    {
        self::assertSame([], SidebarOrder::sanitize($payload));
    }

    /**
     * @return list<list<mixed>>
     */
    public static function fournisseurPayloadInutilisable(): array
    {
        return [
            ['une chaîne'],
            [42],
            [null],
            [[]],                                              // payload vide
            [['sections' => [], 'items' => [], 'subgroups' => []]],
            [['sections' => ['', '   '], 'items' => ['S' => []]]], // que du vide
            [['autreChamp' => 'x']],                            // aucun des 3 champs
        ];
    }

    // ------------------------------------------------------------------
    //  save() — persistance
    // ------------------------------------------------------------------

    public function test_save_ordre_vide_stocke_les_accolades_vides(): void
    {
        SidebarOrder::save([]);

        self::assertSame('{}', $this->valeurStockee(), '[] = ordre par défaut, stocké « {} ».');
        self::assertSame(SidebarOrder::SETTING_KEY, $this->cleStockee());
        self::assertCount(1, $this->pdo->ecritures, 'Une seule écriture.');
    }

    public function test_save_stocke_le_json_sans_echapper_unicode_ni_slashes(): void
    {
        SidebarOrder::save([
            'sections' => ['Comptabilité'],
            'items'    => ['Stock' => ['CRUD / API']],
        ]);

        // Littéral verrouillé : tout échappement (\u00e9, \/) casserait le test.
        self::assertSame(
            '{"sections":["Comptabilité"],"items":{"Stock":["CRUD / API"]}}',
            $this->valeurStockee()
        );
    }

    public function test_save_refuse_un_ordre_trop_volumineux(): void
    {
        $order = ['sections' => [str_repeat('X', SidebarOrder::MAX_JSON_BYTES)]];

        try {
            SidebarOrder::save($order);
            self::fail('Un ordre de plus de 8 KiO devait être refusé.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString((string) SidebarOrder::MAX_JSON_BYTES, $e->getMessage());
        }

        self::assertSame([], $this->pdo->ecritures, 'Rien ne doit être écrit quand on refuse.');
    }

    public function test_save_accepte_un_json_de_exactement_8_kio(): void
    {
        $remplissage = SidebarOrder::MAX_JSON_BYTES - strlen('{"sections":[""]}');
        SidebarOrder::save(['sections' => [str_repeat('a', $remplissage)]]);

        self::assertSame(SidebarOrder::MAX_JSON_BYTES, strlen((string) $this->valeurStockee()));
        self::assertCount(1, $this->pdo->ecritures);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Sème le cache statique de Setting (aucune requête : Setting::load()
     * retourne le cache dès qu'il est non null).
     *
     * @param array<string, string> $settings
     */
    private function semerCache(array $settings): void
    {
        $cache = new \ReflectionProperty(Setting::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, $settings);
    }

    private function stockerOrdre(string $json): void
    {
        $this->semerCache([SidebarOrder::SETTING_KEY => $json]);
    }

    /**
     * Valeur du dernier INSERT INTO settings provoqué par save().
     */
    private function valeurStockee(): ?string
    {
        $derniere = end($this->pdo->ecritures) ?: null;

        return $derniere === null ? null : (string) $derniere['params'][2];
    }

    private function cleStockee(): ?string
    {
        $derniere = end($this->pdo->ecritures) ?: null;

        return $derniere === null ? null : (string) $derniere['params'][1];
    }
}

/**
 * Faux PDO (jamais connecté : créé par réflexion sans constructeur,
 * même approche inoffensive que GuardFakeProdPdo) qui enregistre les
 * écritures au lieu de les exécuter — Setting::set() ne voit pas la
 * différence et aucun SGBD n'est requis.
 */
final class FakeSettingsPdo extends PDO
{
    /** @var list<array{sql: string, params: array<int, string>}> */
    public array $ecritures = [];

    public static function creer(): self
    {
        return (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchArgs): PDOStatement|false
    {
        return $this->statement($query); // SELECT de Setting::load() : aucune ligne.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->statement($query);
    }

    private function statement(string $query): FakeSettingsStatement
    {
        $stmt = new FakeSettingsStatement();
        $stmt->pdo = $this;
        $stmt->sql = $query;

        return $stmt;
    }
}

final class FakeSettingsStatement extends PDOStatement
{
    public ?FakeSettingsPdo $pdo = null;
    public string $sql = '';

    public function execute(?array $params = null): bool
    {
        if ($this->pdo !== null) {
            $this->pdo->ecritures[] = ['sql' => $this->sql, 'params' => $params ?? []];
        }

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
