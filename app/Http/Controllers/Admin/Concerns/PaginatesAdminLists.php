<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

trait PaginatesAdminLists
{
    /** Bounded page size: default when absent/invalid, clamped to the maximum. */
    protected function perPage(Request $request): int
    {
        $value = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);
        if ($value === false) {
            return (int) config('admin.per_page_default');
        }

        return max(1, min((int) config('admin.per_page_max'), $value));
    }

    /**
     * @param  list<mixed>  $data
     * @return array{data: list<mixed>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    protected function page(LengthAwarePaginator $paginator, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
