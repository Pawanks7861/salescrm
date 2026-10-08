<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            try {
                var crmTheme = localStorage.getItem('crm.theme');
                if (crmTheme === 'light' || crmTheme === 'dark') document.documentElement.dataset.theme = crmTheme;
            } catch (e) {}
        </script>
        <title inertia>{{ app(\App\Services\BrandingService::class)->companyName() }} | CRM</title>
        <link rel="icon" id="crm-favicon" href="{{ app(\App\Services\BrandingService::class)->faviconUrl() }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
