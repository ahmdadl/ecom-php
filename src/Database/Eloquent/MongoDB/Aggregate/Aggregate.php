<?php

namespace HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate;

use \MongoDB\BSON\Regex;
use DateTimeInterface;
use HZ\Illuminate\Mongez\Support\MongoDate;
use HZ\Illuminate\Mongez\Support\PeriodDateCalculator;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

class Aggregate
{
    // TODO: Sort
    // TODO: Limit
    // TODO: Skip
    // TODO: Join
    // TODO: Unwind
    // TODO: GeoNear

    /**
     * Query Builder
     *
     * @var mixed
     */
    protected $query;

    /**
     * Pipelines list
     *
     * @var array<int, Pipeline>
     */
    protected $pipelines = [];

    /**
     * Current Pipeline
     * 
     * @var Pipeline
     */
    protected $currentPipeline;

    /**
     * Constructor
     *
     * @param mixed $query
     */
    public function __construct($query)
    {
        $this->query = $query;
    }

    /**
     * Group By the given column
     *
     * @param string|null ...$columns
     */
    public function groupBy(...$columns): Pipeline
    {
        $columnsList = [];

        if (count($columns) === 1 && $columns[0] === null) {
            $columnsList = null;
        } else {
            foreach ($columns as $column) {
                if ($column === null) {
                    continue;
                }

                [$name] = explode('.', $column);

                $columnsList[$name] = "$$column";
            }
        }

        return $this->pipeline('group')->data('_id', $columnsList);
    }

    /**
     * Group By day
     *
     * @param string $column
     */
    public function groupByDay($column): Pipeline
    {
        return $this->pipeline('group')->data('_id', [
            'day' => ['$dayOfMonth' => $this->prepareGroupByDateColumn($column)],
        ]);
    }

    /**
     * Group By full date
     *
     * @param string $column
     */
    public function groupByDate($column): Pipeline
    {
        $preparedColumn = $this->prepareGroupByDateColumn($column);

        return $this->pipeline('group')->data('_id', [
            'day' => ['$dayOfMonth' => $preparedColumn],
            'month' => ['$month' => $preparedColumn],
            'year' => ['$year' => $preparedColumn],
        ]);
    }

    /**
     * Group By month
     *
     * @param string $column
     */
    public function groupByMonth($column): Pipeline
    {
        $preparedColumn = $this->prepareGroupByDateColumn($column);

        return $this->pipeline('group')->data('_id', [
            'month' => ['$month' => $preparedColumn],
        ]);
    }

    /**
     * Group By week
     *
     * @param string $column
     */
    public function groupByWeek($column): Pipeline
    {
        $preparedColumn = $this->prepareGroupByDateColumn($column);

        return $this->pipeline('group')->data('_id', [
            'week' => ['$week' => $preparedColumn],
        ]);
    }

    /**
     * Group By year
     *
     * @param string $column
     */
    public function groupByYear($column): Pipeline
    {
        $preparedColumn = $this->prepareGroupByDateColumn($column);

        return $this->pipeline('group')->data('_id', [
            'year' => ['$year' => $preparedColumn],
        ]);
    }

    /**
     * Prepare group by column for date
     * 
     * @param string $column
     * @return mixed
     */
    public function prepareGroupByDateColumn($column)
    {
        return [
            'date' => '$' . $column,
            'timezone' => date_default_timezone_get(),
        ];
    }

    /**
     * Where clause
     *
     * @param mixed ...$args
     * @return Pipeline
     */
    public function where(...$args)
    {
        return $this->pipeline('match')->where(...$args);
    }

    /**
     * Match documents whose column falls in the current period (or explicit from/to array).
     *
     * @param  PeriodDateCalculator|array{from?: mixed, to?: mixed}|array{0?: mixed, 1?: mixed}  $period
     */
    public function wherePeriod(string $column, PeriodDateCalculator|array $period): Pipeline
    {
        if ($period instanceof PeriodDateCalculator) {
            $from = $period->from;
            $to = $period->to;
        } else {
            $from = $period['from'] ?? $period[0] ?? null;
            $to = $period['to'] ?? $period[1] ?? null;
        }

        return $this->whereBetween($column, $from, $to);
    }

    /**
     * Where between clause (delegates to match pipeline).
     */
    public function whereBetween(string $column, mixed $minValue, mixed $maxValue): Pipeline
    {
        return $this->pipeline('match')->whereBetween($column, $minValue, $maxValue);
    }

    /**
     * Where in clause (delegates to match pipeline).
     *
     * @param array<int|string, mixed> $values
     */
    public function whereIn(string $column, array $values): Pipeline
    {
        return $this->pipeline('match')->whereIn($column, $values);
    }

    /**
     * Where in clause for int values.
     *
     * @param array<int> $values
     */
    public function whereInInt(string $column, array $values): Pipeline
    {
        return $this->pipeline('match')->whereInInt($column, $values);
    }

    /**
     * Optional from/to filter (same semantics as TrafficReportsService date options).
     *
     * - both set → `$gte` / `$lte` via whereBetween
     * - only from → `$gte`
     * - only to → `$lte`
     * - neither → no match stage added; returns this Aggregate for chaining
     */
    public function whereDateRange(string $column, mixed $from = null, mixed $to = null): Aggregate|Pipeline
    {
        if ($from !== null && $to !== null) {
            return $this->whereBetween($column, $from, $to);
        }

        if ($from !== null) {
            return $this->where($column, '>=', $from);
        }

        if ($to !== null) {
            return $this->where($column, '<=', $to);
        }

        return $this;
    }

    /**
     * Run one `$facet` comparing current vs previous period sub-pipelines.
     *
     * `$build` receives a fresh Aggregate (same query) and should add stages after
     * the period match (e.g. `groupBy()->count(...)`).
     *
     * @param  callable(Aggregate): mixed  $build
     * @return array{current: list<array<string, mixed>>, previous: list<array<string, mixed>>}
     */
    public function facetCompareCurrentVsPrevious(
        PeriodDateCalculator $period,
        callable $build,
        string $dateColumn = 'createdAt',
    ): array {
        $inner = new self($this->query);
        $build($inner);
        $innerPipelines = $inner->buildPipelineArray();

        $pipelines = $this->buildPipelineArray();
        $pipelines[] = [
            '$facet' => [
                'current' => array_merge(
                    [$this->dateRangeMatchStage($dateColumn, $period->from, $period->to)],
                    $innerPipelines,
                ),
                'previous' => array_merge(
                    [$this->dateRangeMatchStage($dateColumn, $period->prevFrom, $period->prevTo)],
                    $innerPipelines,
                ),
            ],
        ];

        $raw = $this->runAggregate($pipelines);
        $facet = $raw[0] ?? ['current' => [], 'previous' => []];

        return [
            'current' => $facet['current'] ?? [],
            'previous' => $facet['previous'] ?? [],
        ];
    }

    /**
     * Build a `$match` stage for an inclusive date range (UTCDateTime).
     *
     * @return array{'$match': array<string, mixed>}
     */
    public function dateRangeMatchStage(string $column, mixed $from, mixed $to): array
    {
        return [
            '$match' => [
                $column => [
                    '$gte' => $this->toUtcDateTime($from),
                    '$lte' => $this->toUtcDateTime($to),
                ],
            ],
        ];
    }

    protected function toUtcDateTime(mixed $date): UTCDateTime
    {
        if ($date instanceof UTCDateTime) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return MongoDate::toMongo($date);
        }

        return MongoDate::toMongo($date);
    }

    /**
     * Where like clause
     *
     * @param mixed $value
     * @return Pipeline
     */
    public function whereLike(string $column, $value, string $likeOperator = '')
    {
        $regex = preg_replace('#(^|[^\\\])%#', '$1.*', preg_quote($value));

        if ($likeOperator === 'start') {
            $regex = '^' . $regex;
        }

        if ($likeOperator === 'end') {
            $regex .= '$';
        }

        return $this->pipeline('match')->data([
            $column => new Regex((string) $regex, 'i'),
        ]);
    }

    /**
     * Order returned records
     *
     * @param string $column
     * @param string $order
     * @return Aggregate
     */
    public function orderBy($column, $order = 'asc')
    {
        $pipeline = $this->currentPipeline->name == 'sort' ? $this->currentPipeline : $this->pipeline('sort');

        $columnsList = [];

        $columnsList[$column] = strtolower($order) === 'asc' ? 1 : -1;

        $pipeline->data($columnsList);

        return $this;
    }

    /**
     * Unwind the field list
     *
     * @param string  $includeArrayIndex
     *
     * @return Pipeline
     */
    public function unwind(string $path, $includeArrayIndex = null, bool $preserveNullAndEmptyArrays = false)
    {
        return $this->pipeline('unwind')->unwind($path, $includeArrayIndex, $preserveNullAndEmptyArrays);
    }

    /**
     * Extract the field list
     *
     * @param string  $path
     * @param string  $includeArrayIndex
     *
     * @return Pipeline
     */
    public function extract($path, $includeArrayIndex = null, bool $preserveNullAndEmptyArrays = false)
    {
        return $this->pipeline('unwind')->unwind($path, $includeArrayIndex, $preserveNullAndEmptyArrays);
    }

    /**
     * Join
     *
     * @param string $from
     * @param string $localField
     * @param string $foreignField
     * @param string|null $as
     *
     * @return Pipeline
     */
    public function join($from, $localField, $foreignField, $as = null)
    {
        return $this->pipeline('join')->join($from, $localField, $foreignField, $as);
    }

    /**
     * Limit number of records
     *
     * @param int $number
     * @param int|null $offset
     * @return Pipeline
     */
    public function limit($number, $offset = null)
    {
        if ($offset) {
            $this->offset($offset);
        }

        return $this->pipeline('limit')->limit($number);
    }

    /**
     * Skip number of records
     *
     * @param int $number
     * @return Pipeline
     */
    public function skip($number)
    {
        return $this->pipeline('skip')->skip($number);
    }

    /**
     * Offset number of records
     *
     * @param int $offset
     * @return Pipeline
     */
    public function offset($offset)
    {
        return $this->skip($offset);
    }

    /**
     * Select items
     *
     * @param string ...$columns
     */
    public function select(...$columns): Pipeline
    {
        return $this->project()->select(...$columns);
    }

    /**
     * Select items
     */
    public function project(): Pipeline
    {
        return $this->pipeline('project');
    }

    /**
     * Create new pipeline
     */
    public function pipeline(string $pipelineName): Pipeline
    {
        $this->currentPipeline = new Pipeline($this, $pipelineName);

        $this->pipelines[] = $this->currentPipeline;

        return $this->currentPipeline;
    }

    /**
     * Get the results
     * 
     * @return mixed 
     */
    public function get()
    {
        return $this->runAggregate($this->buildPipelineArray());
    }

    /**
     * Paginate aggregation results using `$facet` (data + count).
     *
     * Returns the same pagination shape as repository list endpoints.
     *
     * @return array{data: list<array<string, mixed>>, paginationInfo: array<string, int>}
     */
    public function paginate(?int $itemsPerPage = null, ?int $page = null): array
    {
        $itemsPerPage = $itemsPerPage
            ?? (int) config('mongez.repository.pagination.itemsPerPage', 15);
        $itemsPerPage = max(1, $itemsPerPage);
        $page = max(1, $page ?? (int) request()->input('page', 1));

        $pipelines = $this->toPaginationPipelines($itemsPerPage, $page);
        $raw = $this->runAggregate($pipelines);
        $facet = $raw[0] ?? ['data' => [], 'meta' => []];

        /** @var list<array<string, mixed>> $data */
        $data = $facet['data'] ?? [];
        $total = (int) ($facet['meta'][0]['total'] ?? 0);

        return [
            'data' => $data,
            'paginationInfo' => [
                'currentResults' => count($data),
                'totalRecords' => $total,
                'numberOfPages' => $total > 0 ? (int) ceil($total / $itemsPerPage) : 0,
                'itemsPerPage' => $itemsPerPage,
                'currentPage' => $page,
            ],
        ];
    }

    /**
     * Build the pipeline list used by {@see paginate()} (includes `$facet`).
     *
     * @return list<array<string, mixed>>
     */
    public function toPaginationPipelines(int $itemsPerPage = 15, int $page = 1): array
    {
        $itemsPerPage = max(1, $itemsPerPage);
        $page = max(1, $page);
        $skip = ($page - 1) * $itemsPerPage;

        $pipelines = $this->buildPipelineArray();
        $pipelines[] = [
            '$facet' => [
                'data' => [
                    ['$skip' => $skip],
                    ['$limit' => $itemsPerPage],
                ],
                'meta' => [
                    ['$count' => 'total'],
                ],
            ],
        ];

        return $pipelines;
    }

    /**
     * Hydrate aggregation rows as Eloquent models.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  class-string<TModel>|null  $modelClass
     * @return \Illuminate\Database\Eloquent\Collection<int, TModel>
     */
    public function hydrate(?string $modelClass = null)
    {
        $modelClass ??= $this->resolveModelClass();

        /** @phpstan-ignore staticMethod.notFound */
        return $modelClass::hydrate($this->get());
    }

    /**
     * Hydrate rows then wrap with a JsonResource / JsonResourceManager class.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  class-string<\Illuminate\Database\Eloquent\Model>|null  $modelClass
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function wrapAs(string $resourceClass, ?string $modelClass = null)
    {
        return $resourceClass::collection($this->hydrate($modelClass));
    }

    /**
     * Process aggregation results in pages without loading everything at once.
     *
     * @param  callable(list<array<string, mixed>>, int): mixed  $callback  Receives rows and 1-based page.
     */
    public function chunk(int $size, callable $callback): void
    {
        $size = max(1, $size);
        $page = 1;

        do {
            $result = $this->paginate($size, $page);
            $rows = $result['data'];

            if ($rows === []) {
                break;
            }

            $callback($rows, $page);

            $page++;
            $hasMore = $page <= ($result['paginationInfo']['numberOfPages'] ?? 0);
        } while ($hasMore);
    }

    /**
     * Yield aggregation rows in chunks (lazy generator over paginated facets).
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function cursor(int $size = 100): \Generator
    {
        $size = max(1, $size);
        $page = 1;

        do {
            $result = $this->paginate($size, $page);
            $rows = $result['data'];

            if ($rows === []) {
                break;
            }

            yield $rows;

            $page++;
            $hasMore = $page <= ($result['paginationInfo']['numberOfPages'] ?? 0);
        } while ($hasMore);
    }

    /**
     * Log the query
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQueryLog()
    {
        return $this->buildPipelineArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildPipelineArray(): array
    {
        $pipelines = [];

        foreach ($this->pipelines as $pipeline) {
            $pipelines[] = [
                $pipeline->getName() => $pipeline->getData(),
            ];
        }

        return $pipelines;
    }

    /**
     * @param  list<array<string, mixed>>  $pipelines
     * @return list<array<string, mixed>>
     */
    protected function runAggregate(array $pipelines): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = iterator_to_array($this->query->raw(function ($query) use ($pipelines) {
            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];

            return $query->aggregate($pipelines, $options);
        }));

        return $result;
    }

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected function resolveModelClass(): string
    {
        if (is_object($this->query) && method_exists($this->query, 'getModel')) {
            return $this->query->getModel()::class;
        }

        throw new \RuntimeException(
            'Cannot hydrate aggregate results without a model class; pass hydrate($modelClass) explicitly.'
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        // for all where clause
        if (Str::startsWith($name, 'where')) {
            /** @var callable $callback */
            $callback = [$this->pipeline('match'), $name];

            return call_user_func_array($callback, $arguments);
        }

        /** @var callable $callback */
        $callback = [$this->currentPipeline, $name];

        return call_user_func_array($callback, $arguments);
    }

    /**
     * Get aggregate framework utilities
     */
    public function utils(): AggregateUtils
    {
        return new AggregateUtils($this);
    }
}
