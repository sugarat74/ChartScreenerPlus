<?php

namespace App\Services\Admin;

use App\Support\UserAgentLabel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read and revoke authenticated sessions stored by the `database` session
 * driver. Session ids never leave the server: the API exposes an opaque,
 * keyed reference (HMAC with the app key) that is only meaningful here.
 */
class SessionDirectory
{
    public function table(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }

    /** Sessions idle longer than the configured lifetime are expired. */
    public function activeSince(): int
    {
        return now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
    }

    /** Authenticated, non-expired sessions. */
    public function active(): Builder
    {
        return $this->table()
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $this->activeSince());
    }

    public function reference(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 40);
    }

    /**
     * @param  object{id: string, user_id: int|string|null, ip_address: string|null, user_agent: string|null, last_activity: int|string}  $row
     * @return array<string, mixed>
     */
    public function present(object $row, ?string $currentSessionId): array
    {
        return [
            'ref' => $this->reference($row->id),
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'ip_address' => $row->ip_address,
            'device' => UserAgentLabel::describe($row->user_agent),
            'last_activity_at' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
            'is_current' => $currentSessionId !== null && hash_equals($row->id, $currentSessionId),
        ];
    }

    /**
     * Resolve an opaque reference back to its session row (authenticated
     * sessions only). The table is small: one row per signed-in browser.
     */
    public function find(string $reference): ?object
    {
        foreach ($this->table()->whereNotNull('user_id')->lazyById(200, 'id') as $row) {
            if (hash_equals($this->reference($row->id), $reference)) {
                return $row;
            }
        }

        return null;
    }

    public function delete(string $sessionId): int
    {
        return $this->table()->where('id', $sessionId)->delete();
    }

    /** Delete every session of a user except `$keepSessionId` (the caller's own). */
    public function deleteForUser(int $userId, ?string $keepSessionId): int
    {
        return $this->table()
            ->where('user_id', $userId)
            ->when($keepSessionId !== null, fn (Builder $query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
