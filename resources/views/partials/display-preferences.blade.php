{{--
    The viewer's own display choices, remembered on this device: text size, and the
    menu's width and whether it is hidden. Applied before the page is drawn, so nothing
    jumps. Browser storage can be unavailable (private windows); the page then simply
    uses the defaults.
--}}
<script>
    (function () {
        var root = document.documentElement;
        try {
            var size = localStorage.getItem('oha.textSize');
            if (size === 'large' || size === 'larger') root.dataset.textSize = size;
            if (localStorage.getItem('oha.sidebar') === 'collapsed') root.dataset.sidebar = 'collapsed';
            var width = parseInt(localStorage.getItem('oha.sidebarWidth') || '', 10);
            if (width >= 208) root.style.setProperty('--sidebar-w', width + 'px');
        } catch (e) { /* storage blocked: defaults apply */ }
    })();
</script>
