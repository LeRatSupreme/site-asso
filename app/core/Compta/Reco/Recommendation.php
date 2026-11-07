<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Recommandation produite par une règle du moteur : DTO de sortie
 * readonly, sérialisable tel quel (toArray = les 16 clés imposées,
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
     * @param string                                            $bloc        'idee' (consigne commerciale R1/R4/R5/R6/R7/R10) | 'correction' (données/rupture R2/R8/R9/R11).
     * @param ?string                                           $debut       Début de validité de la consigne ('Y-m-d'), null si non datée.
     * @param ?string                                           $fin         Fin de validité de la consigne ('Y-m-d'), null si non datée.
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
        public readonly string $statut,
        public readonly string $bloc = 'idee',
        public readonly ?string $debut = null,
        public readonly ?string $fin = null
    ) {
    }

    /**
     * Sérialisation canonique : EXACTEMENT ces 16 clés, dans cet ordre.
     *
     * @return array{id:string,regle:string,titre:string,pourquoi:string,action:string,gain_hebdo:array{bas:float,median:float,haut:float},confiance:string,effort:int,hypotheses:list<string>,produits:list<array{key:string,label:string}>,garde_fous_ok:bool,comment_mesurer:string,statut:string,bloc:string,debut:?string,fin:?string}
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
            'bloc'            => $this->bloc,
            'debut'           => $this->debut,
            'fin'             => $this->fin,
        ];
    }
}
