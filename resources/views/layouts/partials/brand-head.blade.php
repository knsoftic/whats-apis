{{-- Brand colour + default theme (Settings → Appearance). Included in every layout's <head>. --}}
<style>{!! $brand->brandCss() !!}</style>
<script>
    (function () {
        try {
            var allow = @js($brand->bool('theme.allow_toggle'));
            var def = @js($brand->defaultTheme());
            var saved = allow ? localStorage.getItem('theme') : null;
            var dark = saved ? saved === 'dark' : (def === 'dark' || (def === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches));
            if (dark) document.documentElement.classList.add('dark');
        } catch (e) {}
    })();
</script>
@if ($brand->logoUrl())
    <link rel="icon" href="{{ $brand->logoUrl() }}">
@endif
