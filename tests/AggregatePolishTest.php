<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Database\Eloquent\MongoDB\Aggregate\Aggregate;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class AggregatePolishTest extends BaseTestCase
{
    public function test_to_pagination_pipelines_appends_facet_with_skip_limit_and_count(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $aggregate->where('status', 'active');

        $pipelines = $aggregate->toPaginationPipelines(10, 3);

        $this->assertSame([
            ['$match' => ['status' => ['$eq' => 'active']]],
            [
                '$facet' => [
                    'data' => [
                        ['$skip' => 20],
                        ['$limit' => 10],
                    ],
                    'meta' => [
                        ['$count' => 'total'],
                    ],
                ],
            ],
        ], $pipelines);
    }

    public function test_to_pagination_pipelines_clamps_page_and_size(): void
    {
        $aggregate = new Aggregate(new \stdClass());

        $pipelines = $aggregate->toPaginationPipelines(0, 0);
        $facet = $pipelines[0]['$facet'];

        $this->assertSame(0, $facet['data'][0]['$skip']);
        $this->assertSame(1, $facet['data'][1]['$limit']);
    }

    public function test_get_query_log_excludes_pagination_facet(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $aggregate->where('nid', 1);
        $aggregate->toPaginationPipelines(5, 1);

        $this->assertSame([
            ['$match' => ['nid' => ['$eq' => 1]]],
        ], $aggregate->getQueryLog());
    }
}
