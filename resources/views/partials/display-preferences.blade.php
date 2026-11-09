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
            // Text size, as a percentage (90 to 140). An older choice of "Large" or "Larger" carries over.
            var scale = parseInt(localStorage.getItem('oha.textScale') || '', 10);
            if (!scale) scale = { large: 110, larger: 125 }[localStorage.getItem('oha.textSize')] || 100;
            if (scale >= 90 && scale <= 140 && scale !== 100) root.style.fontSize = scale + '%';
            if (localStorage.getItem('oha.sidebar') === 'collapsed') root.dataset.sidebar = 'collapsed';
            var width = parseInt(localStorage.getItem('oha.sidebarWidth') || '', 10);
            if (width >= 208) root.style.setProperty('--sidebar-w', width + 'px');
        } catch (e) { /* storage blocked: defaults apply */ }
    })();
</script>
