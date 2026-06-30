<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Poll;
use App\Models\PollVote;

/**
 * Liste, détail et vote sur les sondages publics.
 */
final class PollController extends Controller
{
    /**
     * Liste des sondages publiés.
     */
    public function index(): void
    {
        $polls = Poll::published();

        $this->render('polls/index', [
            'title'       => 'Sondages — AEIC',
            'description' => 'Donnez votre avis : les sondages de l\'AEIC ouverts aux membres.',
            'polls'       => $polls,
            'count'       => count($polls),
        ]);
    }

    /**
     * Détail d'un sondage : formulaire de vote ou résultats.
     */
    public function show(string $slug): void
    {
        $poll = Poll::findBySlug($slug);

        if ($poll === null) {
            $this->abort(404);
        }

        $pollId   = (string) $poll['id'];
        $isClosed = Poll::isClosed($poll);
        $total    = Poll::totalVotes($pollId);
        $voters   = Poll::totalVoters($pollId);

        $hasVoted  = Auth::check() ? Poll::hasVoted($pollId, (string) Auth::id()) : false;
        $showForm  = Auth::check() && !$hasVoted && !$isClosed;
        $showResults = $hasVoted || $isClosed;

        $this->render('polls/show', [
            'title'        => ($poll['title'] ?? 'Sondage') . ' — AEIC',
            'description'  => trim(strip_tags((string) ($poll['description'] ?? ''))),
            'poll'         => $poll,
            'options'      => Poll::options($pollId),
            'results'      => Poll::results($pollId),
            'userVotes'    => Auth::check() ? Poll::userVotes($pollId, (string) Auth::id()) : [],
            'totalVotes'   => $total,
            'totalVoters'  => $voters,
            'isClosed'     => $isClosed,
            'hasVoted'     => $hasVoted,
            'showForm'     => $showForm,
            'showResults'  => $showResults,
        ]);
    }

    /**
     * Enregistre le vote d'un utilisateur connecté.
     */
    public function vote(string $slug): void
    {
        if (!Auth::check()) {
            redirect(url('/login?callbackUrl=' . rawurlencode('/sondages/' . $slug)));
        }

        $userId = (string) Auth::id();
        $poll   = Poll::findBySlug($slug);

        if ($poll === null) {
            $this->abort(404);
        }

        $pollId = (string) $poll['id'];

        if (Poll::isClosed($poll)) {
            $this->setFlash('error', 'Ce sondage est désormais fermé.');
            redirect(url('/sondages/' . $slug));
        }

        if (Poll::hasVoted($pollId, $userId)) {
            $this->setFlash('info', 'Vous avez déjà voté à ce sondage.');
            redirect(url('/sondages/' . $slug));
        }

        // Choix unique : on prend la première option soumise valide.
        $submitted = $_POST['option_id'] ?? null;
        $optionIds = is_array($submitted)
            ? array_map('strval', $submitted)
            : ($submitted !== null && $submitted !== '' ? [(string) $submitted] : []);

        if ($optionIds === []) {
            $this->setFlash('error', 'Veuillez sélectionner une réponse.');
            redirect(url('/sondages/' . $slug));
        }

        // Pour un sondage à choix unique, on ne garde qu'une seule option.
        if (empty($poll['is_multiple'])) {
            $optionIds = [reset($optionIds)];
        }

        // Valider que les options appartiennent bien à ce sondage.
        $validIds = array_column(Poll::options($pollId), 'id');
        $validIds = array_map('strval', $validIds);

        $count = 0;
        foreach ($optionIds as $optionId) {
            if (in_array($optionId, $validIds, true)) {
                if (PollVote::cast($pollId, $optionId, $userId)) {
                    $count++;
                }
            }
        }

        if ($count > 0) {
            $this->setFlash('success', 'Vote enregistré. Merci !');
        } else {
            $this->setFlash('info', 'Aucun nouveau vote enregistré.');
        }

        redirect(url('/sondages/' . $slug));
    }
}
