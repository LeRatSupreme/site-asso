<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Détail d'un mouvement de liquide PAR COUPURES (pièces et billets).
 *
 * Source unique du catalogue des coupures et de leur arithmétique,
 * partagée par la prise « chez soi » (kiosque + page Caisses) et le
 * dépôt banque : tout total est calculé en CENTIMES ENTIERS (int) —
 * jamais de float intermédiaire, jamais d'arrondi — puis converti en
 * euros au dernier moment. Invariant garanti (et vérifié en base par
 * le CHECK de cash_movement_breakdown) :
 *
 *     ABS(amount) × 100 = Σ (unit_cents × qty)   exactement.
 *
 * Catalogue VERROUILLÉ aux 12 valeurs légales (choix produit assumé,
 * PAS de 100/200/500 €) : 1c 2c 5c 10c 20c 50c 1€ 2€ 5€ 10€ 20€ 50€ —
 * en centimes : 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000.
 *
 * Classe PURE (aucune base, aucun POST direct) : testée unitairement,
 * réutilisée par les contrôleurs (parsing POST) et le rendu (formatage
 * « 3×20€ · 5×2€ · 12×50c » du livre comptable).
 */
final class CashBreakdown
{
    /**
     * Les 12 coupures légales, en centimes, ordre croissant.
     *
     * @var list<int>
     */
    public const UNITS = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000];

    /**
     * Quantités saisies, indexées par coupure en centimes (uniquement
     * les valeurs STRICTEMENT positives, les autres ne sont pas stockées).
     *
     * @var array<int,int>
     */
    private array $quantities;

    /**
     * @param array<int,int> $quantities quantités ≥ 1 par coupure du catalogue
     */
    private function __construct(array $quantities)
    {
        $this->quantities = $quantities;
    }

    /**
     * Depuis un tableau associatif { unit_cents => qty } (ex. lignes de
     * cash_movement_breakdown ou saisie programmatique). Les unités HORS
     * catalogue sont REFUSÉES (coupure inconnue — jamais de 10000+ :
     * le catalogue des 12 valeurs légales est un choix produit assumé) ;
     * les quantités nulles/négatives/fractionnaires/non numériques sont
     * simplement ignorées (qty → 0, non stockée).
     *
     * @param array<array-key,mixed> $posted
     */
    public static function fromArray(array $posted): self
    {
        $valid = array_fill_keys(self::UNITS, true);
        $quantities = [];
        foreach ($posted as $key => $value) {
            $unit = (int) $key;
            if (!isset($valid[$unit])) {
                throw new \InvalidArgumentException(
                    'Coupure inconnue : ' . $key . ' centimes (hors catalogue des 12 valeurs légales).'
                );
            }
            if (!is_numeric($value)) {
                continue;
            }
            $qty = (int) $value;
            if ($qty < 0 || (string) (float) $value !== (string) (float) $qty) {
                continue; // quantité fractionnaire ou négative : ignorée (= 0)
            }
            if ($qty > 0) {
                $quantities[$unit] = $qty;
            }
        }
        ksort($quantities);

        return new self($quantities);
    }

    /**
     * Depuis les champs POST « qty_<cents> » du formulaire de coupures
     * (qty_1 … qty_5000) : champ absent ou invalide → quantité 0.
     *
     * @param array<array-key,mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $posted = [];
        foreach (self::UNITS as $unit) {
            if (array_key_exists('qty_' . $unit, $post)) {
                $posted[$unit] = $post['qty_' . $unit];
            }
        }

        return self::fromArray($posted);
    }

    /**
     * Quantités non nulles, ordre croissant des coupures.
     *
     * @return array<int,int>
     */
    public function quantities(): array
    {
        return $this->quantities;
    }

    /**
     * Total en CENTIMES ENTIERS : Σ (unit_cents × qty).
     */
    public function totalCents(): int
    {
        $total = 0;
        foreach ($this->quantities as $unit => $qty) {
            $total += $unit * $qty;
        }

        return $total;
    }

    /**
     * Total en euros (conversion au dernier moment : le centime entier
     * devient un décimal à 2 chiffres exacts une fois formaté en base).
     */
    public function amount(): float
    {
        return $this->totalCents() / 100;
    }

    /**
     * Aucune coupure saisie (total nul) ?
     */
    public function isEmpty(): bool
    {
        return $this->quantities === [];
    }

    /**
     * Le détail retombe-t-il EXACTEMENT sur ce montant (en euros) ?
     * Contrôle bloquant avant toute écriture : Σ(unit×qty) = |amount|×100.
     */
    public function matchesAmount(float $amount): bool
    {
        return $this->totalCents() === (int) round(abs($amount) * 100);
    }

    /**
     * Formatage d'affichage « 3×20€ · 5×2€ · 12×50c » : grosses coupures
     * d'abord (unit_cents DESC, même ordre que la requête du livre), les
     * valeurs < 1 € en centimes (« 50c »), les autres en euros (« 20€ »).
     * Total vide → chaîne vide.
     */
    public function format(): string
    {
        $parts = [];
        foreach (array_reverse($this->sortedUnits()) as $unit) {
            $parts[] = $this->quantities[$unit] . '×' . $this->unitLabel($unit);
        }

        return implode(' · ', $parts);
    }

    /**
     * Formatage d'UNE coupure isolée (même convention que format()).
     */
    public function unitLabel(int $unitCents): string
    {
        if (!in_array($unitCents, self::UNITS, true)) {
            throw new \InvalidArgumentException('Coupure inconnue : ' . $unitCents . ' centimes (hors catalogue des 12 valeurs légales).');
        }

        return $unitCents >= 100 ? ((string) (int) ($unitCents / 100)) . '€' : $unitCents . 'c';
    }

    /**
     * Unités saisies, ordre croissant (clés triées de quantities()).
     *
     * @return list<int>
     */
    private function sortedUnits(): array
    {
        $units = array_keys($this->quantities);
        sort($units);

        return $units;
    }
}
