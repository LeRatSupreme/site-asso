<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Poll;
use App\Models\PollOption;

/**
 * CRUD des sondages.
 */
final class AdminPollController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/polls/index', [
            'title' => 'Sondages',
            'polls' => Poll::allForAdmin(),
        ]);
    }

    public function form(?string $id = null): void
    {
        $this->guard();

        $poll = ['is_published' => 0, 'is_multiple' => 0];
        $options = [];

        if ($id !== null) {
            $found = Poll::find($id);
            if ($found !== null) {
                $poll    = $found;
                $options = Poll::options((string) $found['id']);
            }
        }

        $this->renderAdmin('admin/polls/form', [
            'title'   => isset($poll['id']) ? 'Modifier le sondage' : 'Nouveau sondage',
            'poll'    => $poll,
            'options' => $options,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        $data  = $_POST;
        $isNew = empty($data['id']);

        $id = Poll::save($data);

        // Remplacement complet des options : on supprime les anciennes puis
        // on recrée les nouvelles reçues du formulaire.
        PollOption::deleteByPoll($id);

        $labels = $data['options'] ?? [];
        if (is_array($labels)) {
            $order = 0;
            foreach ($labels as $label) {
                $label = trim((string) $label);
                if ($label === '') {
                    continue;
                }
                PollOption::save($id, $label, $order);
                $order++;
            }
        }

        $this->audit($isNew ? 'poll.create' : 'poll.update', 'poll', $id);

        $this->setFlash('success', 'Sondage enregistré.');
        redirect(url('/admin/sondages'));
    }

    public function delete(string $id): void
    {
        $this->guard();

        $poll = Poll::find($id);
        if ($poll !== null) {
            Poll::deleteRow($id);
            $this->audit('poll.delete', 'poll', $id);
            $this->setFlash('success', 'Sondage supprimé.');
        }

        redirect(url('/admin/sondages'));
    }
}
