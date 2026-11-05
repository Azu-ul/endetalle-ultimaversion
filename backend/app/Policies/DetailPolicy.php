<?php

namespace App\Policies;

use App\Models\Detail;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Detalles cargados por colaboradores (StudioController).
 * El autor ve y edita lo suyo, también lo publicado (en ese caso vuelve a revisión,
 * ver StudioController::reopen); el superadmin, todo. Lo archivado solo lo toca el admin.
 */
class DetailPolicy
{
    private const EDITABLE = ['draft', 'pending', 'rejected', 'published'];

    public function before(User $user): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function view(User $user, Detail $detail): Response
    {
        return (int) $detail->author_id === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Detail $detail): Response
    {
        $view = $this->view($user, $detail);
        if ($view->denied()) {
            return $view;
        }

        return in_array($detail->status, self::EDITABLE, true)
            ? Response::allow()
            : Response::denyWithStatus(422, 'Este detalle está archivado. Para cambiarlo, escribile al administrador.');
    }

    public function delete(User $user, Detail $detail): Response
    {
        $view = $this->view($user, $detail);
        if ($view->denied()) {
            return $view;
        }

        return in_array($detail->status, ['draft', 'rejected'], true)
            ? Response::allow()
            : Response::denyWithStatus(422, 'Solo se pueden eliminar borradores o detalles rechazados.');
    }
}
