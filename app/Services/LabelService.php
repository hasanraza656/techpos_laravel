<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class LabelService
{
    public const LOCALES = ['en', 'ur', 'ur-Latn', 'ar', 'hi'];

    public function resolve(int $tenantId, string $key, string $locale, string $defaultLocale): string
    {
        foreach (array_unique([$locale, $defaultLocale]) as $language) {
            $value = DB::table('label_overrides')->where('tenant_id', $tenantId)->where('key', $key)->where('locale', $language)->value('value');
            if ($value !== null) {
                return $value;
            }
        }
        foreach (array_unique([$locale, 'en']) as $language) {
            $value = DB::table('label_translations')->where('key', $key)->where('locale', $language)->where('review_status', 'reviewed')->value('value');
            if ($value !== null) {
                return $value;
            }
        }

        return $key;
    }
}
