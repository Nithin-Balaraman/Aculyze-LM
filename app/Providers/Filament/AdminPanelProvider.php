<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\MyDashboard;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            /*
             * Phase 2, "Left panel UI fix": Filament's 20rem default left
             * more table columns than fit before the right panel needed
             * horizontal scrolling. 16rem still comfortably fits the
             * longest nav label ("Stage Dropout Report") without wrapping.
             */
            ->sidebarWidth('16rem')
            ->brandName('Aculyze LM')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            /*
             * IBM Plex Sans is already bundled and loaded via
             * resources/css/filament/admin/theme.css (see @import block at
             * the top of that file), so no `url` is passed here —
             * LocalFontProvider renders no <link> tag at all when the url
             * is blank. This just stops Filament from also requesting its
             * own default (Inter, via the Bunny Fonts CDN) on every page
             * load for a font we never use.
             */
            ->font('IBM Plex Sans', provider: LocalFontProvider::class)
            /*
             * Brand palette, from the real Aculyze logo. `coral` is a
             * deliberately separate, restrained accent — it is used
             * EXCLUSIVELY for Hot leads and urgent stale-record alerts
             * (see App\Enums\LeadTemperature and the stale table widgets),
             * never for general UI chrome. `gold` and `slateblue` exist
             * only for the Warm/Cold lead-temperature badges, so none of
             * the three temperatures reuses Filament's generic
             * danger/warning/info palette.
             */
            ->colors([
                'primary' => Color::hex('#4174B9'),
                'navy' => Color::hex('#0E1131'),
                'info' => Color::hex('#2DC4ED'),
                'coral' => Color::hex('#F0653C'),
                'gold' => Color::hex('#C99A3D'),
                'slateblue' => Color::hex('#5B7C99'),
                'gray' => Color::hex('#64748b'),
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                MyDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->navigationGroups([
                'Sales',
                'Reports',
                'Administration',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
