@php
    $branding = app(\App\Services\BrandingService::class);
    $company = $branding->companyName();
    $logo = $branding->url('logo');
@endphp
<x-mail::layout>
{{-- Header: client branding only --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($logo)
<img src="{{ url($logo) }}" class="logo" alt="{{ $company }}" style="height: 48px; max-height: 48px; width: auto; max-width: 220px; object-fit: contain;">
@else
{{ $company }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $company }}. {{ __('All rights reserved.') }}

<span style="font-size: 11px; opacity: 0.65;">Powered by <a href="{{ config('crm.platform.url') }}" style="color: inherit; font-weight: 500;">{{ config('crm.platform.name') }}</a></span>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
