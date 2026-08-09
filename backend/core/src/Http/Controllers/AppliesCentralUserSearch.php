<?php

namespace QCH\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait AppliesCentralUserSearch
{
    /**
     * Default User columns to search when matching by central DB user (multi-DB tenancy).
     */
    protected static function defaultCentralUserSearchColumns(): array
    {
        return [
            'name', 'last_name', 'second_last_name',
            'email', 'phone', 'code',
        ];
    }

    /**
     * Applies an OR filter so that records whose related user (central DB) matches
     * the search term are included. Use in buildIndexFetchQuery when the resource
     * has user_id and searchableBy cannot include user.* (cross-DB).
     */
    protected function applyCentralUserSearchToQuery(
        Builder $query,
        ?string $searchValue,
        array $userColumns = []
    ): void {
        if ($searchValue === null || trim($searchValue) === '') {
            return;
        }

        $columns = $userColumns !== []
            ? $userColumns
            : static::defaultCentralUserSearchColumns();

        $term = '%'.trim($searchValue).'%';
        $userIds = User::query()
            ->where(function (Builder $q) use ($columns, $term) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'ilike', $term);
                }
            })
            ->pluck('id')
            ->toArray();

        if ($userIds !== []) {
            $query->orWhereIn('user_id', $userIds);
        }
    }
}
