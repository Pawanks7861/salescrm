@php($company = app(\App\Services\BrandingService::class)->companyName())
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ $company }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ $company }}. @lang('All rights reserved.')

            Powered by {{ config('crm.platform.name') }} ({{ config('crm.platform.url') }})
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
