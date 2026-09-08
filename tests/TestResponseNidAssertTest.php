<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Testing\TestResponse;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class TestResponseNidAssertTest extends BaseTestCase
{
    public function test_assert_record_nid_passes_for_matching_value(): void
    {
        $response = $this->makeResponse([
            'data' => [
                'record' => ['nid' => 42, 'name' => 'Desk'],
            ],
        ]);

        $response->assertRecordNid(42);

        $this->addToAssertionCount(1);
    }

    public function test_assert_records_have_nid_passes_for_list(): void
    {
        $response = $this->makeResponse([
            'data' => [
                'records' => [
                    ['nid' => 1],
                    ['nid' => 2],
                ],
            ],
        ]);

        $response->assertRecordsHaveNid();

        $this->addToAssertionCount(2);
    }

    /**
     * @param  array<string, mixed> $payload
     */
    private function makeResponse(array $payload): TestResponse
    {
        $base = new Response(json_encode($payload), 200, [
            'Content-Type' => 'application/json',
        ]);

        $response = TestResponse::fromBaseResponse($base);
        $response->setTestSuit($this);

        return $response;
    }
}
