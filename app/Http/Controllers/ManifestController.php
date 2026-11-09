<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/** PWA manifest; the installed app's name includes the organisation from the settings. */
class ManifestController extends Controller
{
    public function __invoke(Settings $settings): JsonResponse
    {
        return response()->json([
            'name' => $settings->appTitle(),
            'short_name' => 'Jedáleň',
            'description' => 'Objednávanie obedov a večerí pre zamestnancov',
            'lang' => 'sk',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f5f5f4',
            'theme_color' => '#047857',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
