<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_preserves_platform_configuration_and_entitlements(): void
    {
        $this->seed();
        $id = DB::table('plans')->where('key', 'standard')->value('id');
        DB::table('plans')->where('id', $id)->update(['name' => 'Business plan']);
        DB::table('plan_prices')->where('plan_id', $id)->update(['amount' => 6500]);
        DB::table('feature_entitlements')->where('plan_id', $id)->delete();
        DB::table('payment_methods')->where('key', 'bank')->update(['details' => json_encode(['account_number' => 'custom-account'])]);
        $this->seed();
        $this->assertDatabaseHas('plans', ['id' => $id, 'name' => 'Business plan']);
        $this->assertDatabaseHas('plan_prices', ['plan_id' => $id, 'amount' => 6500]);
        $this->assertEquals(0, DB::table('feature_entitlements')->where('plan_id', $id)->count());
        $this->assertSame('custom-account', json_decode(DB::table('payment_methods')->where('key', 'bank')->value('details'), true)['account_number']);
    }

    public function test_payment_instructions_come_from_environment_and_unconfigured_methods_stay_inactive(): void
    {
        config(['services.payment_instructions.jazzcash.account_number' => null]);
        $this->seed();
        $bank = DB::table('payment_methods')->where('key', 'bank')->first();
        $this->assertTrue((bool) $bank->active);
        $this->assertSame(['bank' => 'Test Bank', 'account_number' => 'TEST-0000000001', 'account_holder' => 'Test Holder'], json_decode($bank->details, true));
        $this->assertFalse((bool) DB::table('payment_methods')->where('key', 'jazzcash')->value('active'));
        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonMissing(['name' => 'JazzCash']);
    }
}
