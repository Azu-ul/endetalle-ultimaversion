<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\DetailAccess;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->currentSubscription();
        $plan = $subscription?->plan ?? Plan::where('slug', 'free')->first();
        $endsAt = $subscription?->ends_at;
        $access = new DetailAccess($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            // Tiene una compra dentro del plazo de reembolso (botón "Solicitar reembolso")
            'refund_eligible' => app(\App\Services\Billing\RefundService::class)->hasEligibleOrder($this->resource),
            'avatar_url' => $this->avatar_url,
            'cover_url' => $this->cover_url,
            'profession' => $this->profession,
            'company' => $this->company,
            'bio' => $this->bio,
            'birth_date' => $this->birth_date?->toDateString(),
            'interests' => $this->interests ?? [],
            'portfolio_url' => $this->portfolio_url,
            'roles' => $this->getRoleNames()->values(),
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'level' => $plan->level,
                'detail_limit' => $plan->detail_limit,
                'allows_dwg' => (bool) $plan->allows_dwg,
                'allows_skp' => (bool) $plan->allows_skp,
                'allows_ebooks' => (bool) $plan->allows_ebooks,
            ],
            'quota' => [
                'used' => $access->quotaUsed(),
                // El staff (admin y colaboradores) tiene acceso total: sin cupo.
                'limit' => $access->isStaff() ? null : $plan->detail_limit,
            ],
            // Plan menor ya pago que empieza cuando vence el actual
            'scheduled' => ($scheduled = app(\App\Services\Payments\PlanPurchase::class)->scheduledSubscription($this->resource)) ? [
                'plan' => $scheduled->plan->only(['slug', 'name']),
                'starts_at' => $scheduled->starts_at->toIso8601String(),
                'ends_at' => $scheduled->ends_at->toIso8601String(),
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'provider' => $subscription->provider,
                // Cancelada: sigue activa hasta ends_at, pero no se renueva ni se le avisa el vencimiento
                'cancelled' => $subscription->cancelled_at !== null,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
                'days_left' => $endsAt
                    ? max(0, (int) ceil(($endsAt->getTimestamp() - now()->getTimestamp()) / 86400))
                    : null,
            ] : null,
        ];
    }
}
