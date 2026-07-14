<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendTranslationImportsTest extends TestCase
{
    public function test_vue_components_using_translate_import_it(): void
    {
        $missingImports = collect(File::allFiles(resource_path('js')))
            ->filter(fn ($file) => $file->getExtension() === 'vue')
            ->filter(function ($file) {
                $contents = File::get($file->getPathname());

                return preg_match('/\btranslate\s*\(/', $contents)
                    && ! preg_match('/import\s*\{[^}]*\btranslate\b[^}]*\}\s*from\s*[\'\"]@\/i18n[\'\"]/', $contents);
            })
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $missingImports, 'Vue components use translate() without importing it: '.implode(', ', $missingImports));
    }
}
