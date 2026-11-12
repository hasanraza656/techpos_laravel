<?php

namespace App\Http\Controllers\Api;

use App\Domain\Operations\CatalogCsv;
use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CatalogTransferController extends Controller
{
    public function import(Request $r, TenantContext $c, CatalogCsv $csv)
    {
        $d = $r->validate(['file' => 'required|file', 'mode' => 'required|in:create,update', 'preview' => 'required|boolean']);
        $result = $csv->import($c, $r->file('file'), $d['mode'], (bool) $d['preview']);
        if (! $result['preview'] && $result['errors']) {
            return response()->json(['message' => 'No products were imported. Fix the listed rows and preview again.',
                'errors' => ['file' => array_map(fn ($e) => 'Row '.$e['row'].': '.implode(' ', $e['messages']), $result['errors'])]], 422);
        }

        return response()->json($result);
    }

    public function export(TenantContext $c)
    {
        $c->authorize('products.view');
        $c->authorize('reports.export');
        app(Entitlements::class)->authorize($c->tenant->id, 'bulk_data');
        $columns = array_values(array_diff(CatalogCsv::COLUMNS, $c->allows('products.view_cost') ? [] : ['cost_price']));
        Audit::record('catalog.csv_exported', $c->tenant->id);

        return response()->streamDownload(function () use ($c, $columns) {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, $columns, ',', '"', '');
            DB::table('products')->where('tenant_id', $c->tenant->id)->whereNull('deleted_at')->orderBy('id')->chunkById(500, function ($products) use ($c, $columns, $stream) {
                $barcodes = DB::table('product_barcodes')->where('tenant_id', $c->tenant->id)->whereIn('product_id', $products->pluck('id'))->get()->groupBy('product_id');
                foreach ($products as $p) {
                    $row = [];
                    foreach ($columns as $key) {
                        $value = $key === 'barcodes' ? json_encode(($barcodes->get($p->id) ?? collect())->map(fn ($b) => ['barcode' => $b->barcode, 'quantity' => bcadd((string) $b->quantity, '0', 3)])->values()->all()) : ($p->$key ?? '');
                        if (in_array($key, ['active', 'tax_inclusive'])) {
                            $value = $value ? '1' : '0';
                        }
                        $row[] = CatalogCsv::spreadsheetSafe((string) $value);
                    }
                    fputcsv($stream, $row, ',', '"', '');
                }
            });
            fclose($stream);
        }, 'techpos-products.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
