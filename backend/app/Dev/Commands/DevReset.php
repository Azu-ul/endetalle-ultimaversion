<?php

namespace App\Dev\Commands;

use App\Dev\Commands\Concerns\OnlyInLocal;
use App\Models\RefundRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DevReset extends Command
{
    use OnlyInLocal;

    protected $signature = 'dev:reset {email : Email del usuario}';

    protected $description = 'SOLO LOCAL: deja al usuario en Free y borra sus órdenes, pagos, solicitudes de reembolso y planes (también los encolados)';

    public function handle(): int
    {
        if ($this->refusesOutsideLocal()) {
            return self::FAILURE;
        }
        if (! $user = $this->findUser($this->argument('email'))) {
            return self::FAILURE;
        }

        $counts = DB::transaction(function () use ($user) {
            $refunds = RefundRequest::where('user_id', $user->id)->get();
            $refunds->each(fn (RefundRequest $r) => Storage::disk('local')->deleteDirectory("refund-receipts/{$r->id}"));

            return [
                'solicitudes de reembolso' => RefundRequest::where('user_id', $user->id)->delete(),
                'pagos' => DB::table('payments')->where('user_id', $user->id)->delete(),
                'órdenes' => DB::table('orders')->where('user_id', $user->id)->delete(),
                'planes (activos, cancelados y encolados)' => DB::table('subscriptions')->where('user_id', $user->id)->delete(),
            ];
        });

        $this->info("Listo: {$user->email} quedó en Free. Se borró:");
        foreach ($counts as $what => $n) {
            $this->line("  - {$n} {$what}");
        }
        $this->printSummary($user);

        return self::SUCCESS;
    }
}
