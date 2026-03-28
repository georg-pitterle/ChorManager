<?php

declare(strict_types=1);

namespace App\Util;

final class TableQueryParams
{
    public static function from(array $params, array $sortableColumns): array
    {
        $defaultSort = $sortableColumns[0] ?? 'id';

        $sort = (string) ($params['sort'] ?? $defaultSort);
        if (!in_array($sort, $sortableColumns, true)) {
            $sort = $defaultSort;
        }

        $dir = strtolower((string) ($params['dir'] ?? 'asc'));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($params['per_page'] ?? 25)));

        $view = (string) ($params['view'] ?? 'table');
        if (!in_array($view, ['table', 'cards'], true)) {
            $view = 'table';
        }

        return [
            'sort' => $sort,
            'dir' => $dir,
            'q' => trim((string) ($params['q'] ?? '')),
            'page' => $page,
            'per_page' => $perPage,
            'view' => $view,
        ];
    }
}
