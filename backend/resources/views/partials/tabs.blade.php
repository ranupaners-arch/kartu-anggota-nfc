{{-- $tabs: [key => label], $current: key aktif, $param: nama query (default "tab") --}}
<div class="tabs">
    @foreach ($tabs as $key => $label)
        <a href="{{ request()->fullUrlWithQuery([($param ?? 'tab') => $key, 'page' => null]) }}" class="{{ $current === $key ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>
