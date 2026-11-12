<?php

namespace App\Services;

use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenantMedia
{
    public static function present(?object $row): ?array
    {
        return $row ? ['public_id' => $row->public_id, 'url' => '/api/v1/media/'.$row->public_id,
            'width' => $row->width, 'height' => $row->height] : null;
    }

    public function store(TenantContext $c, UploadedFile $file, ?object $product = null): array
    {
        $c->authorize($product ? 'products.edit' : 'onboarding.manage');
        abort_if($product && (int) $product->tenant_id !== (int) $c->tenant->id, 404);
        validator(['image' => $file], ['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:4096'])->validate();
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000 || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages(['image' => 'Choose a JPEG, PNG or WebP up to 4 MB and 16 megapixels.']);
        }
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['image' => 'This image could not be read. Choose another file.']);
        }
        $scale = min(1, 1200 / max($dimensions[0], $dimensions[1]));
        $width = max(1, (int) round($dimensions[0] * $scale));
        $height = max(1, (int) round($dimensions[1] * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $dimensions[0], $dimensions[1]);
        ob_start();
        $ok = imagewebp($image, null, 82);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($image);
        if (! $ok || ! $bytes) {
            throw new \RuntimeException('Unable to encode the product image.');
        }
        $uuid = (string) Str::uuid();
        $path = 'private/tenant-media/'.$c->tenant->public_id.'/'.$uuid.'.webp';
        Storage::disk('local')->put($path, $bytes);
        try {
            return DB::transaction(function () use ($c, $product, $uuid, $path, $bytes, $width, $height) {
                DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
                $slot = $product ? 'product:'.$product->public_id : 'logo';
                $old = DB::table('tenant_media')->where('tenant_id', $c->tenant->id)->where('slot', $slot)->first();
                $row = ['public_id' => $uuid, 'tenant_id' => $c->tenant->id, 'product_id' => $product?->id, 'slot' => $slot,
                    'kind' => $product ? 'product' : 'logo', 'path' => $path, 'mime' => 'image/webp', 'bytes' => strlen($bytes),
                    'width' => $width, 'height' => $height, 'sha256' => hash('sha256', $bytes), 'updated_at' => now()];
                if ($old) {
                    DB::table('tenant_media')->where('id', $old->id)->update($row);
                    DB::afterCommit(fn () => Storage::disk('local')->delete($old->path));
                } else {
                    DB::table('tenant_media')->insert($row + ['created_at' => now()]);
                }
                Audit::record('media.saved', $c->tenant->id, $uuid, ['kind' => $row['kind'], 'product' => $product?->public_id]);

                return self::present((object) $row);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function remove(TenantContext $c, ?object $product = null): void
    {
        $c->authorize($product ? 'products.edit' : 'onboarding.manage');
        abort_if($product && (int) $product->tenant_id !== (int) $c->tenant->id, 404);
        DB::transaction(function () use ($c, $product) {
            DB::table('tenants')->where('id', $c->tenant->id)->lockForUpdate()->first();
            $row = DB::table('tenant_media')->where('tenant_id', $c->tenant->id)->where('slot', $product ? 'product:'.$product->public_id : 'logo')->first();
            if ($row) {
                DB::table('tenant_media')->where('id', $row->id)->delete();
                DB::afterCommit(fn () => Storage::disk('local')->delete($row->path));
                Audit::record('media.removed', $c->tenant->id, $row->public_id);
            }
        });
    }
}
