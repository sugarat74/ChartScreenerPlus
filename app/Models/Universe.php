<?php

namespace App\Models;

use Database\Factories\UniverseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of Instruments. A Screener universe (`is_screener`) is one the
 * user can screen (S&P 500, ...); the Market Context universe is not. Its
 * Listing Market and currency are shared by all members, and its visibility
 * to non-Admins follows `config('universes.public')` (D9 of
 * docs/specs/multi-market-universes.md).
 */
#[Fillable(['name', 'slug', 'market', 'currency', 'is_screener', 'position'])]
class Universe extends Model
{
    /** @use HasFactory<UniverseFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_screener' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The slug of the default Screener universe.
     */
    public static function defaultSlug(): string
    {
        return (string) config('ingestion.universe');
    }

    /**
     * Only Screener universes, in selector order.
     *
     * @param  Builder<Universe>  $query
     */
    public function scopeScreener(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_screener'), true)
            ->orderBy($query->qualifyColumn('position'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Screener universes the caller may see: all of them for an Admin
     * (preview), only the public ones (`SCREENER_UNIVERSES_PUBLIC`) for
     * anyone else. This is the one visibility check of every surface.
     *
     * @param  Builder<Universe>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where($query->qualifyColumn('is_screener'), true);

        if ($user?->isAdmin() !== true) {
            $query->whereIn($query->qualifyColumn('slug'), (array) config('universes.public', []));
        }
    }

    /**
     * Whether this universe is a Screener universe visible to `$user`.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $this->is_screener) {
            return false;
        }

        return $user?->isAdmin() === true
            || in_array($this->slug, (array) config('universes.public', []), true);
    }

    /**
     * The instruments that belong to this universe.
     *
     * @return BelongsToMany<Instrument, $this>
     */
    public function instruments(): BelongsToMany
    {
        return $this->belongsToMany(Instrument::class);
    }
}
