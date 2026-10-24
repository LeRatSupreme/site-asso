<?php

declare(strict_types=1);

/**
 * Pont de test (fonction GLOBALE db(), sans namespace) : CostLotStock lit
 * les ventes/pertes via le helper global db() (app/config/database.php),
 * qui n'est pas chargé par le bootstrap PHPUnit — un appel \db() y lève un
 * Error, avalé par le try/catch interne : forProduct()/ecarts() verraient
 * silencieusement 0 vente et 0 perte.
 *
 * Le pont est INACTIF par défaut : hors tests de CostLotStockDbTest, db()
 * lève un Error — exactement comme une fonction absente, pour ne pas
 * changer le comportement des autres classes (Auth::refreshUser,
 * Permissions::userHasExtraPage… comptent sur l'échec de db()). Les tests
 * l'activent via db_test_bridge_enable() et le désactivent en tearDown.
 *
 * Active, db() pointe aeic_test (mêmes variables d'environnement que
 * TestDatabaseTrait) : la MÊME base que le PDO injecté aux modèles.
 * Chargé par CostLotStockDbTest via require_once.
 */

/**
 * État d'activation du pont (public pour rester simple à lire en test).
 */
final class DbTestBridge
{
    public static bool $enabled = false;
}

/** Active db() (pont branché sur aeic_test) pour les tests en cours. */
function db_test_bridge_enable(): void
{
    DbTestBridge::$enabled = true;
}

/** Désactive db() (redevient « fonction absente » pour le reste du process). */
function db_test_bridge_disable(): void
{
    DbTestBridge::$enabled = false;
}

if (!function_exists('db')) {
    function db(): PDO
    {
        if (!DbTestBridge::$enabled) {
            // Même effet que l'absence de la fonction : Error attrapé par
            // les try/catch \Throwable des appelants (comportement historique).
            throw new \Error('db() de test désactivée (pont CostLotStockDbTest inactif).');
        }

        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $name = getenv('DB_NAME') ?: 'aeic_test';
        $user = getenv('DB_USER') ?: 'aeic';
        $pass = getenv('DB_PASS') ?: '';

        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        try {
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (\PDOException) {
            // certains environnements n'ont pas les tables tz : non bloquant.
        }

        return $pdo;
    }
}
