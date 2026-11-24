<?php

namespace App\Domain\Operations;

use App\Http\Controllers\Api\ProductController;
use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CatalogCsv
{
    public const COLUMNS = ['name', 'sku', 'type', 'unit', 'brand', 'description', 'cost_price', 'selling_price', 'wholesale_price', 'tax_rate', 'tax_inclusive', 'reorder_level', 'active', 'barcodes', 'version'];

    public function import(TenantContext $c, UploadedFile $file, string $mode, bool $preview): array
    {
        validator(['mode' => $mode], ['mode' => 'required|in:create,update'])->validate();
        app(Entitlements::class)->authorize($c->tenant->id, 'bulk_data');
        $c->authorize($mode === 'create' ? 'products.create' : 'products.edit');
        validator(['file' => $file], ['file' => 'required|file|mimes:csv,txt|max:2048'])->validate();
        $bytes = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            throw ValidationException::withMessages(['file' => 'Use a UTF-8 CSV file without binary data.']);
        }
        $handle = fopen($file->getRealPath(), 'rb');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        if (! $headers) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The CSV is empty.']);
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        if (count(array_unique($headers)) !== count($headers) || array_diff($headers, self::COLUMNS) || ! in_array('sku', $headers) || ($mode === 'create' && (! in_array('name', $headers) || ! in_array('selling_price', $headers))) || ($mode === 'update' && ! in_array('version', $headers))) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Use the supported headers. SKU is required; create also requires name and selling_price, update requires version. Stock quantities cannot be imported.']);
        }
        if (in_array('cost_price', $headers)) {
            $c->authorize('products.view_cost');
        }
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) === 1 && ($line[0] === null || trim($line[0]) === '')) {
                continue;
            }
            $rows[] = $line;
            if (count($rows) > 250) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Import up to 250 products per file. Split larger catalogs into batches.']);
            }
        }
        fclose($handle);
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Add at least one product row below the header.']);
        }
        $errors = [];
        $valid = 0;
        $seen = [];
        $sample = [];
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            foreach ($rows as $i => $line) {
                $row = count($line) === count($headers) ? array_combine($headers, array_map(fn ($v) => self::fromSpreadsheet(trim((string) $v)), $line)) : [];
                $sku = $row['sku'] ?? '';
                try {
                    if (! $row || $sku === '' || isset($seen[$sku])) {
                        throw ValidationException::withMessages(['row' => 'Each row needs matching columns and a distinct, nonempty SKU.']);
                    }
                    $seen[$sku] = true;
                    $old = DB::table('products')->where('tenant_id', $c->tenant->id)->where('sku', $sku)->whereNull('deleted_at')->first();
                    if ($mode === 'update' && ! $old) {
                        throw ValidationException::withMessages(['sku' => 'This SKU does not exist in your business. Choose create mode for new products.']);
                    }
                    if ($mode === 'create' && $old) {
                        throw ValidationException::withMessages(['sku' => 'This SKU already exists. Choose update mode and provide its current version.']);
                    }
                    $data = ['name' => $old?->name ?? '', 'sku' => $sku, 'type' => $old?->type ?? 'standard', 'unit' => $old?->unit ?? 'piece',
                        'brand' => $old?->brand, 'description' => $old?->description, 'selling_price' => (string) ($old?->selling_price ?? '0'),
                        'wholesale_price' => $old?->wholesale_price === null ? null : bcadd((string) $old->wholesale_price, '0', 2), 'tax_rate' => (string) ($old?->tax_rate ?? '0'), 'tax_inclusive' => (bool) ($old?->tax_inclusive ?? false),
                        'reorder_level' => (string) ($old?->reorder_level ?? '0'), 'active' => (bool) ($old?->active ?? true),
                        'barcodes' => $old ? DB::table('product_barcodes')->where('tenant_id', $c->tenant->id)->where('product_id', $old->id)->get(['barcode', 'quantity'])->map(fn ($b) => ['barcode' => $b->barcode, 'quantity' => bcadd((string) $b->quantity, '0', 3)])->all() : []];
                    if (! $old) {
                        $data['cost_price'] = '0';
                    }
                    foreach ($row as $key => $value) {
                        if ($key === 'barcodes') {
                            if (str_starts_with($value, '[')) {
                                try {
                                    $data[$key] = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
                                } catch (\JsonException) {
                                    throw ValidationException::withMessages(['barcodes' => 'Use a valid barcode JSON array or separate single-unit barcodes with |.']);
                                }
                            } else {
                                $data[$key] = array_map(fn ($code) => ['barcode' => trim($code), 'quantity' => '1'], array_filter(explode('|', $value), fn ($v) => trim($v) !== ''));
                            }
                        } elseif (in_array($key, ['tax_inclusive', 'active'])) {
                            if (! in_array(strtolower($value), ['true', 'false', '1', '0'])) {
                                throw ValidationException::withMessages([$key => 'Use true/false or 1/0 for boolean fields.']);
                            }
                            $data[$key] = in_array(strtolower($value), ['true', '1']);
                        } elseif ($key === 'version') {
                            if ($mode === 'update') {
                                $data[$key] = $value;
                            }
                        } else {
                            $data[$key] = $value === '' && in_array($key, ['brand', 'description', 'wholesale_price']) ? null : $value;
                        }
                    }
                    $request = Request::create('/catalog-import', 'POST', $data);
                    $request->setUserResolver(fn () => $c->user);
                    app(ProductController::class)->save($request, $c, $mode === 'update' ? $old->public_id : null);
                    $valid++;
                    if (count($sample) < 5) {
                        $sample[] = ['sku' => $sku, 'name' => $data['name'], 'selling_price' => $data['selling_price']];
                    }
                } catch (ValidationException $e) {
                    $errors[] = ['row' => $i + 2, 'sku' => $sku, 'messages' => array_merge(...array_values($e->errors()))];
                } catch (HttpExceptionInterface $e) {
                    $errors[] = ['row' => $i + 2, 'sku' => $sku, 'messages' => [$e->getMessage() ?: 'This action is not permitted.']];
                }
            }
            $committed = ! $preview && ! $errors;
            if ($committed) {
                Audit::record('catalog.csv_imported', $c->tenant->id, null, ['mode' => $mode, 'count' => $valid, 'file_sha256' => hash('sha256', $bytes)]);
                DB::commit();
            }

            return ['preview' => $preview, 'mode' => $mode, 'rows' => count($rows), 'valid_rows' => $valid, 'errors' => $errors, 'sample' => $sample, 'committed' => $committed];
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    public static function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }

    // Reverse spreadsheetSafe() so an exported catalog re-imports without gaining apostrophes.
    public static function fromSpreadsheet(string $value): string
    {
        return preg_match('/^\'[\s]*[=+@\-\t\r]/u', $value) ? substr($value, 1) : $value;
    }
}
