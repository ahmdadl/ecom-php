<?php

namespace HZ\Illuminate\Mongez\Tests;

use HZ\Illuminate\Mongez\Http\Validation\Unique;
use HZ\Illuminate\Mongez\Tests\Fixtures\Product;
use Illuminate\Support\Facades\Validator;

final class UniqueRuleTest extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCollections('products');

        Validator::extend('uniqueMongo', Unique::class . '@passes');
    }

    /**
     * Validate the given rule against the shared email value
     *
     * @param string $rule
     * @return bool
     */
    private function validate(string $rule): bool
    {
        return Validator::make(
            ['email' => 'taken@store.com'],
            ['email' => $rule]
        )->passes();
    }

    public function test_fails_when_value_exists_on_another_record(): void
    {
        Product::query()->create(['email' => 'taken@store.com']);

        $otherRecord = Product::query()->create(['name' => 'Other']);

        $this->assertFalse($this->validate("uniqueMongo:products,email,{$otherRecord->nid}"));
    }

    public function test_passes_when_ignoring_the_own_record_by_default_nid_column(): void
    {
        $ownRecord = Product::query()->create(['email' => 'taken@store.com']);

        $this->assertTrue($this->validate("uniqueMongo:products,email,{$ownRecord->nid}"));
    }

    public function test_explicit_ignore_column_is_honored(): void
    {
        Product::query()->create([
            'email' => 'taken@store.com',
            'price' => 55,
        ]);

        $this->assertTrue($this->validate('uniqueMongo:products,email,55,price'));
        $this->assertFalse($this->validate('uniqueMongo:products,email,77,price'));
    }
}
