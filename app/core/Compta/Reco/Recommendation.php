<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Recommandation produite par une règle du moteur : DTO de sortie
 * readonly, sérialisable tel quel (toArray = les 13 clés imposées,
 * consommées par la couche d'affichage/persistance).
 */
final class Recommendation
{
    /**
     * @param string                                            $id          Identifiant unique de la reco.
     * @param string                                            $regle       Règle émettrice ('R1' … 'R11').
     * @param string                                            $titre       Titre court affiché.
     * @param string                                            $pourquoi    Justification (données observées).
     * @param string                                            $action      Action concrète proposée.
     * @param array{bas:float,median:float,haut:float}          $gainHebdo   Gain hebdomadaire estimé (€).
     * @param string                                            $confiance   'faible' | 'moyenne' | 'haute'.
     * @param int                                               $effort      Effort d'application 1–3.
     * @param list<string>                                      $hypotheses  Hypothèses derrière l'estimation.
     * @param list<array{key:string,label:string}>              $produits    Produits concernés.
     * @param bool                                              $gardeFousOk true si tous les garde-fous passent.
     * @param string                                            $commentMesurer Protocole de mesure de l'effet.
     * @param string                                            $statut      Statut ('proposée', 'refusée', …).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $regle,
        public readonly string $titre,
        public readonly string $pourquoi,
        public readonly string $action,
        public readonly array $gainHebdo,
        public readonly string $confiance,
        public readonly int $effort,
        public readonly array $hypotheses,
        public readonly array $produits,
        public readonly bool $gardeFousOk,
        public readonly string $commentMesurer,
        public readonly string $statut
    ) {
    }

    /**
     * Sérialisation canonique : EXACTEMENT ces 13 clés, dans cet ordre.
     *
     * @return array{id:string,regle:string,titre:string,pourquoi:string,action:string,gain_hebdo:array{bas:float,median:float,haut:float},confiance:string,effort:int,hypotheses:list<string>,produits:list<array{key:string,label:string}>,garde_fous_ok:bool,comment_mesurer:string,statut:string}
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'regle'           => $this->regle,
            'titre'           => $this->titre,
            'pourquoi'        => $this->pourquoi,
            'action'          => $this->action,
            'gain_hebdo'      => $this->gainHebdo,
            'confiance'       => $this->confiance,
            'effort'          => $this->effort,
            'hypotheses'      => $this->hypotheses,
            'produits'        => $this->produits,
            'garde_fous_ok'   => $this->gardeFousOk,
            'comment_mesurer' => $this->commentMesurer,
            'statut'          => $this->statut,
        ];
    }
}
