<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantMedia;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class SeedDemoProductImages extends Command
{
    protected $signature = 'techpos:demo-images {email} {--tenant= : Public business UUID}';

    protected $description = 'Attach bundled generated photos to explicitly seeded local TEST products; preserve existing images';

    public function handle(): int
    {
        if (! app()->environment('local') || ! $this->option('tenant')) {
            $this->error('Local development and an explicit --tenant UUID are required.');

            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->first();
        $tenant = Tenant::where('public_id', $this->option('tenant'))->first();
        if (! $user || ! $tenant || ! DB::table('tenant_users')->where('user_id', $user->id)->where('tenant_id', $tenant->id)->where('is_owner', true)->exists()
            || ! DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'local.requested_testing_seed.v1')->exists()) {
            $this->error('The named owner/business must have the explicit local test dataset audit marker.');

            return self::FAILURE;
        }
        $context = new TenantContext;
        $context->tenant = $tenant;
        $context->user = $user;
        $context->owner = true;
        auth()->setUser($user);
        $added = 0;
        $skipped = 0;
        for ($i = 1; $i <= 30; $i++) {
            $sku = 'TEST-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $product = DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $sku)->where('name', 'like', '[TEST] %')->whereNull('deleted_at')->first();
            if (! $product || DB::table('tenant_media')->where('tenant_id', $tenant->id)->where('product_id', $product->id)->exists()) {
                $skipped++;

                continue;
            }
            $path = resource_path('demo-products/'.$sku.'.webp');
            if (! is_file($path)) {
                $this->error('Bundled photo missing for '.$sku);

                return self::FAILURE;
            }
            app(TenantMedia::class)->store($context, new UploadedFile($path, $sku.'.webp', 'image/webp', null, true), $product);
            $added++;
        }
        Audit::record('local.demo_images_assigned', $tenant->id, $tenant->public_id, ['added' => $added, 'skipped' => $skipped]);
        $this->info('Images added: '.$added.'. Existing images or non-test products skipped: '.$skipped.'.');

        return self::SUCCESS;
    }
}
