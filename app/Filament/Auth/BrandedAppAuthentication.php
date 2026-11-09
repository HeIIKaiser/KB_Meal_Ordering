<?php

namespace App\Filament\Auth;

use App\Support\Settings;
use Filament\Auth\MultiFactor\App\AppAuthentication;

/**
 * TOTP 2FA whose issuer name ("Jedáleň <firma>") comes from the settings at the time of setup,
 * not from code, so every installation shows its own organisation in the authenticator app.
 */
class BrandedAppAuthentication extends AppAuthentication
{
    public function getBrandName(): string
    {
        return app(Settings::class)->appTitle();
    }
}
