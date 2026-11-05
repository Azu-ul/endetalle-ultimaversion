<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Detail extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'materials' => 'array',
            'is_verified' => 'boolean',
            'is_public' => 'boolean',
            'published_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** Publicado y con la fecha ya cumplida: un detalle programado para el futuro todavía no se ve. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** Publicado pero con la fecha todavía por venir. */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('published_at', '>', now());
    }

    public function isScheduled(): bool
    {
        return $this->status === 'published' && $this->published_at?->isFuture() === true;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function requiredPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'required_plan_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'detail_tag');
    }

    public function images(): HasMany
    {
        return $this->hasMany(DetailImage::class)->orderBy('sort_order');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DetailFile::class);
    }
}