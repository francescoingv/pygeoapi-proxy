<?php

namespace App\Services;

use App\Enums\Ogc\ExecutionStatus;
use App\Enums\TableKey;
use App\Models\ProcessExecution;
use App\Models\User;
use App\Services\Ogc\ProcessExecutionLocalization;
use App\Support\TablePagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class JobTableQuery
{
    public function __construct(private ProcessExecutionLocalization $localization) {}

    /**
     * @param  array{search: string, status: string, role: string}  $filters
     * @param  array{sorting: list<array{id: string, desc: bool}>, pageSize: int, columnVisibility: array<string, bool>}  $settings
     * @return array{executions: LengthAwarePaginator<int, ProcessExecution>, statusCounts: array<string, int>}
     */
    public function paginate(User $viewer, TableKey $table, array $filters, array $settings, int $page, ?int $ownerId = null): array
    {
        $adminTable = $table === TableKey::AdminJobs;
        $query = ProcessExecution::query();

        if ($adminTable) {
            $query->with(['user:id,name,email,avatar_path', 'user.socialAccounts:id,user_id,avatar,updated_at']);
            if ($ownerId !== null) {
                $query->where('user_id', $ownerId);
            }
        } else {
            $query->where('user_id', $viewer->id);
        }

        $title = $filters['search'] !== '' || in_array('process', array_column($settings['sorting'], 'id'), true)
            ? $this->localizedTitle($query)
            : ['sql' => 'process_title', 'bindings' => []];

        if ($filters['search'] !== '') {
            $this->search($query, $filters['search'], $adminTable, $viewer->isAdmin(), $title);
        }

        $counts = (clone $query)->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count)->all();

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $this->sort($query, $settings['sorting'], $title);

        return [
            'executions' => TablePagination::paginate($query, $settings['pageSize'], $page),
            'statusCounts' => $counts,
        ];
    }

    /**
     * @param  Builder<ProcessExecution>  $query
     * @return array{sql: string, bindings: list<string>}
     */
    private function localizedTitle(Builder $query): array
    {
        $mysql = $query->getConnection()->getDriverName() === 'mysql';
        $processes = (clone $query)->setEagerLoads([]);
        if ($mysql) {
            $processes->selectRaw('BINARY process_id AS process_id, BINARY process_version AS process_version');
        } else {
            $processes->select(['process_id', 'process_version']);
        }
        $sql = 'CASE';
        $bindings = [];

        foreach ($processes->distinct()->get() as $execution) {
            $title = $this->localization->translatedTitle($execution);
            if ($title === null) {
                continue;
            }
            $condition = $mysql
                ? 'BINARY process_id = ? AND BINARY process_version = ?'
                : 'process_id = ? COLLATE BINARY AND process_version = ? COLLATE BINARY';
            $sql .= " WHEN {$condition} THEN ?";
            array_push($bindings, $execution->process_id, $execution->process_version, $title);
        }

        return $bindings === []
            ? ['sql' => 'process_title', 'bindings' => []]
            : ['sql' => $sql.' ELSE process_title END', 'bindings' => $bindings];
    }

    /**
     * @param  Builder<ProcessExecution>  $query
     * @param  array{sql: string, bindings: list<string>}  $title
     */
    private function search(Builder $query, string $search, bool $includeOwner, bool $includeRemoteId, array $title): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $query->where(function (Builder $query) use ($pattern, $search, $includeOwner, $includeRemoteId, $title): void {
            foreach (['name', 'process_id', 'message', 'status'] as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
            $query->orWhereRaw("LOWER({$title['sql']}) LIKE ? ESCAPE '!'", [...$title['bindings'], $pattern]);
            if (ctype_digit($search)) {
                $query->orWhere('id', $search);
            }
            if ($includeRemoteId) {
                $query->orWhereRaw("LOWER(remote_job_id) LIKE ? ESCAPE '!'", [$pattern]);
            }
            if ($includeOwner) {
                $query->orWhereHas('user', function (Builder $query) use ($pattern): void {
                    $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
                });
            }
        });
    }

    /**
     * @param  Builder<ProcessExecution>  $query
     * @param  list<array{id: string, desc: bool}>  $sorting
     * @param  array{sql: string, bindings: list<string>}  $localizedTitle
     */
    private function sort(Builder $query, array $sorting, array $localizedTitle): void
    {
        $sqlite = $query->getConnection()->getDriverName() === 'sqlite';
        $title = "COALESCE(NULLIF({$localizedTitle['sql']}, ''), process_id)";
        $fallbackName = $sqlite
            ? "TRIM({$title} || ' ' || COALESCE(strftime('%d/%m/%Y %H:%M', created_at), ''))"
            : "TRIM(CONCAT({$title}, ' ', COALESCE(DATE_FORMAT(created_at, '%d/%m/%Y %H:%i'), '')))";
        $ownerName = $sqlite ? "name || ' ' || email" : "CONCAT(name, ' ', email)";
        $statusOrder = 'CASE status';
        foreach (ExecutionStatus::cases() as $position => $status) {
            $statusOrder .= " WHEN '{$status->value}' THEN {$position}";
        }
        $statusOrder .= ' ELSE 7 END';
        $columns = [
            'process' => "LOWER(COALESCE(NULLIF(TRIM(name), ''), {$fallbackName}))",
            'user' => "(SELECT LOWER({$ownerName}) FROM users WHERE users.id = process_executions.user_id)",
            'status' => $statusOrder,
            'jobId' => 'id',
            'message' => 'LOWER(message)',
            'createdAt' => 'created_at',
            'submittedAt' => 'submitted_at',
            'finishedAt' => 'COALESCE(completed_at, failed_at)',
            'progress' => 'progress',
        ];
        foreach ($sorting as $sort) {
            if (isset($columns[$sort['id']])) {
                $direction = $sort['desc'] ? 'desc' : 'asc';
                $query->orderByRaw($columns[$sort['id']].' '.$direction, $sort['id'] === 'process' ? $localizedTitle['bindings'] : []);
            }
        }
        $query->orderByDesc('id');
    }
}
