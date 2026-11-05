<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Suscripciones que están rigiendo ahora: las activas y las encoladas (status "scheduled") cuya fecha de
     * inicio ya llegó. Así el cambio de plan se nota apenas vence el anterior, sin esperar al cron.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'active')
                ->orWhere(fn (Builder $s) => $s->where('status', 'scheduled')->where('starts_at', '<=', now()));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
