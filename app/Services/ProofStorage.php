<?php

namespace App\Services;

use App\Models\SubscriptionPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProofStorage
{
    /** File written by store() whose database row has not committed yet. */
    private ?string $uncommitted = null;

    /**
     * Callers run store() inside their own transaction. If that transaction rolls back after the
     * file was written (e.g. a later audit insert fails), call this to remove the orphaned file.
     */
    public function discardUncommitted(): void
    {
        if ($this->uncommitted) {
            Storage::disk('local')->delete($this->uncommitted);
            $this->uncommitted = null;
        }
    }

    public function store(SubscriptionPayment $p, UploadedFile $file): void
    {
        // Decode and re-encode raster input: SVG/PDF/original executable bytes never enter storage.
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 16000000 || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
            throw ValidationException::withMessages(['proof' => 'Use an image no larger than 6000px / 16 megapixels.']);
        }
        \App\Support\ImageMemory::assertDecodable($dimensions[0], $dimensions[1], 'proof');
        $image =@imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['proof' => 'The screenshot cannot be decoded.']);
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = 'private/payment-proofs/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $bytes);
        $this->uncommitted = $path;
        DB::afterCommit(function () use ($path) {
            if ($this->uncommitted === $path) {
                $this->uncommitted = null;
            }
        });
        $old =DB::table('payment_proofs')->where('subscription_payment_id', $p->id)->value('path');
        try {
            DB::table('payment_proofs')->updateOrInsert(['subscription_payment_id' => $p->id], ['path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($old) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($old));
        }
    }
}
