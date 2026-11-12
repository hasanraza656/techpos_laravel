<?php

namespace App\Domain\Platform;

use Illuminate\Http\Request;

final class Filters
{
    public static function read(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:150', 'user_q' => 'nullable|string|max:150', 'client' => 'nullable|uuid', 'branch' => 'nullable|uuid', 'warehouse' => 'nullable|uuid', 'status' => 'nullable|string|max:40', 'plan' => 'nullable|string|max:80', 'due' => 'nullable|in:overdue,next7,next30', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
    }

    public static function search($query, array $d, array $columns): void
    {
        if (! empty($d['q'])) {
            $term = '%'.addcslashes($d['q'], '%_\\').'%';
            $query->where(function ($q) use ($columns, $term) {
                foreach ($columns as $i => $c) {
                    $i === 0 ? $q->where($c, 'like', $term) : $q->orWhere($c, 'like', $term);
                }
            });
        }
    }

    public static function client($q, array $d, string $column = 't.public_id'): void
    {
        if (! empty($d['client'])) {
            $q->where($column, $d['client']);
        }
    }

    public static function due($q, array $d, string $column, bool $dateOnly = false): void
    {
        $start = $dateOnly ? now()->toDateString() : now();
        if (($d['due'] ?? '') === 'overdue') {
            $q->whereNotNull($column)->where($column, '<', $start);
        } elseif (in_array($d['due'] ?? '', ['next7', 'next30'])) {
            $end = now()->addDays($d['due'] === 'next7' ? 7 : 30);
            $q->whereBetween($column, [$start, $dateOnly ? $end->toDateString() : $end]);
        }
    }
}
