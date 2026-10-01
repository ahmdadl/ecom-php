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

    public function test_geo_near_builds_correct_stage_with_required_params(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $aggregate->geoNear([-73.99, 40.73], 'dist.calculated');

        $pipelines = $aggregate->getQueryLog();

        $this->assertCount(1, $pipelines);
        $this->assertSame([
            '$geoNear' => [
                'near' => [-73.99, 40.73],
                'distanceField' => 'dist.calculated',
                'spherical' => true,
            ],
        ], $pipelines[0]);
    }

    public function test_geo_near_builds_correct_stage_with_all_optional_params(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $aggregate->geoNear(
            ['type' => 'Point', 'coordinates' => [-73.99, 40.73]],
            'distance',
            5000,
            100,
            ['category' => 'restaurant'],
            0.001,
            false,
            'loc'
        );

        $pipelines = $aggregate->getQueryLog();

        $this->assertCount(1, $pipelines);
        $stage = $pipelines[0]['$geoNear'];
        $this->assertSame(['type' => 'Point', 'coordinates' => [-73.99, 40.73]], $stage['near']);
        $this->assertSame('distance', $stage['distanceField']);
        $this->assertSame(5000, $stage['maxDistance']);
        $this->assertSame(100, $stage['minDistance']);
        $this->assertSame(['category' => 'restaurant'], $stage['query']);
        $this->assertSame(0.001, $stage['distanceMultiplier']);
        $this->assertFalse($stage['spherical']);
        $this->assertSame('loc', $stage['includeLocs']);
    }

    public function test_geo_near_throws_when_not_first_stage(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $aggregate->where('status', 'active');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$geoNear must be the first stage');

        $aggregate->geoNear([-73.99, 40.73], 'distance');
    }

    public function test_geo_near_allows_chaining_after(): void
    {
        $aggregate = new Aggregate(new \stdClass());
        $aggregate->geoNear([-73.99, 40.73], 'distance');
        $aggregate->where('category', 'restaurant');
        $aggregate->limit(10);

        $pipelines = $aggregate->getQueryLog();

        $this->assertCount(3, $pipelines);
        $this->assertSame([
            '$geoNear' => [
                'near' => [-73.99, 40.73],
                'distanceField' => 'distance',
                'spherical' => true,
            ],
        ], $pipelines[0]);
        $this->assertSame([
            '$match' => ['category' => ['$eq' => 'restaurant']],
        ], $pipelines[1]);
        // Pipeline::limit() stores value as array key (pre-existing behavior)
        $this->assertSame([
            '$limit' => [10 => null],
        ], $pipelines[2]);
    }
}
