<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleService;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogCsvTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->create($this->owner, 'CSV Shop');
        $this->actingAs($this->owner)->withHeader('X-Tenant-ID', $this->tenant->public_id);
    }

    private function enable(): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['plan_id' => DB::table('plans')->where('key', 'standard')->value('id')]);
        app(ModuleService::class)->set($this->tenant->id, 'bulk_data', true);
    }

    private function csv(array $header, array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('products.csv', $bytes);
    }

    private function upload(UploadedFile $file, bool $preview = true, string $mode = 'create')
    {
        return $this->post('/api/v1/operations/catalog/import', ['file' => $file, 'preview' => $preview ? '1' : '0', 'mode' => $mode], ['Accept' => 'application/json']);
    }

    public function test_bulk_csv_is_denied_on_free_and_when_module_is_disabled(): void
    {
        $this->upload($this->csv(['name', 'sku', 'selling_price'], [['Tea', 'TEA', '100']]))->assertForbidden();
        $this->getJson('/api/v1/operations/catalog/export')->assertForbidden();
        $this->enable();
        app(ModuleService::class)->set($this->tenant->id, 'bulk_data', false);
        $this->upload($this->csv(['name', 'sku', 'selling_price'], [['Tea', 'TEA', '100']]))->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_preview_persists_nothing_and_commit_uses_normal_catalog_rules_without_stock(): void
    {
        $this->enable();
        $header = ['name', 'sku', 'selling_price', 'cost_price', 'barcodes'];
        $rows = [['Tea', 'TEA', '100', '60', json_encode([['barcode' => 'PACK|6', 'quantity' => '6.000']])], ['Rice', 'RICE', '250', '175', 'RICE-001|RICE-ALT']];
        $audit = DB::table('audit_logs')->count();
        $this->upload($this->csv($header, $rows))->assertOk()->assertJsonPath('valid_rows', 2)->assertJsonPath('committed', false)->assertJsonCount(0, 'errors');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_price_history', 0);
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $this->upload($this->csv($header, $rows), false)->assertOk()->assertJsonPath('committed', true);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_price_history', 2);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseHas('product_barcodes', ['tenant_id' => $this->tenant->id, 'barcode' => 'PACK|6', 'quantity' => 6]);
    }

    public function test_invalid_or_duplicate_rows_roll_back_the_entire_commit(): void
    {
        $this->enable();
        $header = ['name', 'sku', 'selling_price'];
        foreach ([[['Tea', 'TEA', '100'], ['Bad', 'BAD', '-1']], [['Tea', 'TEA', '100'], ['Duplicate', 'TEA', '200']]] as $rows) {
            $this->upload($this->csv($header, $rows))->assertOk()->assertJsonPath('valid_rows', 1)->assertJsonCount(1, 'errors');
            $this->upload($this->csv($header, $rows), false)->assertUnprocessable();
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('product_price_history', 0);
        }
    }

    public function test_update_requires_current_revision_and_preserves_omitted_fields_and_barcodes(): void
    {
        $this->enable();
        $this->upload($this->csv(['name', 'sku', 'selling_price', 'barcodes'], [['Tea', 'TEA', '100', json_encode([['barcode' => 'TEA-PACK', 'quantity' => '6.000']])]]), false)->assertOk();
        $this->upload($this->csv(['sku', 'version', 'selling_price'], [['TEA', '1', '125']]), false, 'update')->assertOk()->assertJsonPath('committed', true);
        $this->assertDatabaseHas('products', ['tenant_id' => $this->tenant->id, 'sku' => 'TEA', 'selling_price' => 125, 'version' => 2, 'name' => 'Tea']);
        $this->assertDatabaseHas('product_barcodes', ['barcode' => 'TEA-PACK', 'quantity' => 6]);
        $this->upload($this->csv(['sku', 'version', 'selling_price'], [['TEA', '1', '999']]), false, 'update')->assertUnprocessable();
        $this->assertDatabaseHas('products', ['sku' => 'TEA', 'selling_price' => 125, 'version' => 2]);
        $this->upload($this->csv(['sku', 'version'], [['UNKNOWN', '1']]), false, 'update')->assertUnprocessable();
    }

    public function test_export_has_current_versions_safe_text_and_lossless_barcode_quantities(): void
    {
        $this->enable();
        $this->upload($this->csv(['name', 'sku', 'selling_price', 'barcodes'], [['=HYPERLINK("evil")', 'TEA', '100', json_encode([['barcode' => 'PACK|6', 'quantity' => '6.000']])]]), false)->assertOk();
        $response = $this->get('/api/v1/operations/catalog/export')->assertOk();
        $bytes = $response->streamedContent();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        $row = array_combine($header, fgetcsv($stream, 0, ',', '"', ''));
        fclose($stream);
        $this->assertSame("'=HYPERLINK(\"evil\")", $row['name']);
        $this->assertSame('1', $row['version']);
        $this->assertSame('6.000', json_decode($row['barcodes'], true)[0]['quantity']);
        $this->assertArrayHasKey('cost_price', $row);
    }

    public function test_unknown_stock_columns_binary_and_oversized_batches_are_rejected(): void
    {
        $this->enable();
        $this->upload($this->csv(['name', 'sku', 'selling_price', 'quantity'], [['Tea', 'TEA', '100', '999']]))->assertUnprocessable();
        $this->upload(UploadedFile::fake()->createWithContent('binary.csv', "name,sku,selling_price\nBad\0,TEA,100"))->assertUnprocessable();
        $rows = [];
        for ($i = 0; $i < 251; $i++) {
            $rows[] = ['Tea', 'TEA-'.$i, '100'];
        }
        $this->upload($this->csv(['name', 'sku', 'selling_price'], $rows))->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_export_redacts_cost_and_import_cannot_bypass_staff_permissions(): void
    {
        $this->enable();
        $this->upload($this->csv(['name', 'sku', 'selling_price', 'cost_price'], [['Tea', 'TEA', '100', '60']]), false)->assertOk();
        $role = $this->postJson('/api/v1/roles', ['name' => 'CSV reader', 'permissions' => ['products.view', 'reports.export']])->assertOk()->json('public_id');
        $staff = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $staff->id]);
        $this->postJson('/api/v1/role-assignments', ['user' => $staff->public_id, 'role' => $role])->assertOk();
        $this->actingAs($staff);
        $bytes = $this->get('/api/v1/operations/catalog/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('cost_price', $bytes);
        $this->upload($this->csv(['name', 'sku', 'selling_price'],[['Bad', 'BAD', '100']]), false)->assertForbidden();
        $this->assertDatabaseCount('products',1);
    }
}
