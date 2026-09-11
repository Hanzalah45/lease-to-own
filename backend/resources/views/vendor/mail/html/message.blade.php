<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url', config('app.url'))">
<img src="{{ rtrim((string) config('app.frontend_url', config('app.url')), '/') }}/logo.png" class="logo" width="100" height="65" alt="Prostart Leasing">
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
Prostart Leasing · Equipment lease-to-own
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
