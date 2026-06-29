<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\TeamMember;

/**
 * Gestion de l'équipe (membres du bureau affichés sur /team).
 */
final class AdminTeamController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/team/index', [
            'title'   => 'Équipe',
            'members' => TeamMember::allForAdmin(),
        ]);
    }

    public function form(?string $id = null): void
    {
        $this->guard();

        $member = ['is_active' => 1, 'is_highlight' => 0, 'order' => 0];
        if ($id !== null) {
            $found = TeamMember::find($id);
            if ($found !== null) {
                $member = $found;
            }
        }

        $this->renderAdmin('admin/team/form', [
            'title'  => isset($member['id']) ? 'Modifier le membre' : 'Nouveau membre',
            'member' => $member,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        $isNew = empty($_POST['id']);
        $id = TeamMember::save($_POST);

        $this->audit($isNew ? 'team.create' : 'team.update', 'team_member', $id);
        $this->setFlash('success', 'Membre enregistré.');
        redirect(url('/admin/team'));
    }

    public function delete(string $id): void
    {
        $this->guard();

        TeamMember::deleteRow($id);
        $this->audit('team.delete', 'team_member', $id);
        $this->setFlash('success', 'Membre supprimé.');
        redirect(url('/admin/team'));
    }
}
