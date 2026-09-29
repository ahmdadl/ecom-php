<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Support\NidIndexPlan;
use HZ\Illuminate\Mongez\Support\NidIndexSpec;

class NidIndexPlanTest extends TestCase
{
    public function test_unlisted_collections_get_a_unique_nid_index(): void
    {
        $plan = NidIndexPlan::resolve('orders', []);

        $this->assertCount(1, $plan->nidIndexes);

        $nid = $plan->nidIndexes[0];

        $this->assertSame(['nid' => 1], $nid->keys);
        $this->assertSame('nid_1', $nid->name);
        $this->assertTrue($nid->unique);
        $this->assertTrue($nid->isUniqueNidIndex());
    }

    public function test_default_nid_index_can_be_turned_off(): void
    {
        $plan = NidIndexPlan::resolve('orders', [], false);

        $this->assertSame([], $plan->nidIndexes);
        $this->assertSame([], $plan->required());
        $this->assertSame([NidIndexPlan::LEGACY_INDEX], $plan->dropIndexes, 'the legacy index still goes');
    }

    public function test_an_explicitly_empty_nid_list_opts_out(): void
    {
        $plan = NidIndexPlan::resolve('courier', [
            'courier' => [
                'nid' => [],
                'additional' => [
                    ['keys' => ['code' => 1, 'published' => 1], 'unique' => false],
                ],
            ],
        ]);

        $this->assertSame([], $plan->nidIndexes);
        $this->assertCount(1, $plan->additionalIndexes);
        $this->assertSame('code_1_published_1', $plan->additionalIndexes[0]->name);
    }

    public function test_it_resolves_declared_nid_and_additional_indexes(): void
    {
        $plan = NidIndexPlan::resolve('installationteamcapacities', [
            'installationteamcapacities' => [
                'nid' => [
                    ['keys' => ['nid' => 1]],
                    ['keys' => ['installationTeam.nid' => 1, 'city.nid' => 1], 'unique' => true],
                ],
                'additional' => [
                    ['keys' => ['published' => 1], 'unique' => false],
                    ['keys' => ['idempotencyKey' => 1], 'unique' => true, 'sparse' => true],
                ],
            ],
        ]);

        $names = array_map(static fn (NidIndexSpec $spec): string => $spec->name, $plan->required());

        $this->assertSame([
            'nid_1',
            'installationTeam.nid_1_city.nid_1',
            'published_1',
            'idempotencyKey_1',
        ], $names);
    }

    public function test_extra_create_options_are_passed_through(): void
    {
        $plan = NidIndexPlan::resolve('orders', [
            'orders' => [
                'additional' => [
                    ['keys' => ['idempotencyKey' => 1], 'unique' => true, 'sparse' => true],
                ],
            ],
        ]);

        $options = $plan->additionalIndexes[0]->createOptions();

        $this->assertTrue($options['unique']);
        $this->assertTrue($options['sparse']);
        $this->assertSame('idempotencyKey_1', $options['name']);
    }

    public function test_the_legacy_id_index_is_always_dropped(): void
    {
        $plan = NidIndexPlan::resolve('orders', [
            'orders' => [
                'drop' => ['legacy_unique_name_1'],
            ],
        ]);

        $this->assertContains(NidIndexPlan::LEGACY_INDEX, $plan->dropIndexes);
        $this->assertContains('legacy_unique_name_1', $plan->dropIndexes);
        $this->assertSame(array_unique($plan->dropIndexes), $plan->dropIndexes);
    }

    public function test_a_composite_nid_index_is_not_treated_as_the_unique_nid_index(): void
    {
        $plan = NidIndexPlan::resolve('installationteamusages', [
            'installationteamusages' => [
                'nid' => [
                    ['keys' => ['installationTeam.nid' => 1, 'city.nid' => 1, 'date' => 1]],
                ],
            ],
        ]);

        $this->assertFalse($plan->nidIndexes[0]->isUniqueNidIndex());
    }
}
