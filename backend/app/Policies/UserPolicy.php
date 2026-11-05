<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Qué puede hacer el admin con otras cuentas desde el panel. */
class UserPolicy
{
    /** El superadmin es único: no se le cambia rol, estado ni plan desde el panel. */
    public function manage(User $admin, User $target): Response
    {
        if (! $admin->hasRole('superadmin')) {
            return Response::deny('Solo el superadmin puede administrar cuentas.');
        }

        if ($target->hasRole('superadmin')) {
            return Response::deny('La cuenta del superadmin se edita desde "Mi cuenta".');
        }

        return Response::allow();
    }
}
