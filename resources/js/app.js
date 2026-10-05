import Alpine from 'alpinejs';

/*
 * The Board Chairperson's signature pad: draw with a mouse, pen or finger. On submit
 * the drawing is sent as a PNG; an empty pad is refused before anything is sent.
 */
Alpine.data('signaturePad', () => ({
    empty: true,
    drawing: false,

    init() {
        const canvas = this.$refs.canvas;
        const ctx = canvas.getContext('2d');
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#191c1e';

        const point = (e) => {
            const r = canvas.getBoundingClientRect();
            return { x: (e.clientX - r.left) * (canvas.width / r.width), y: (e.clientY - r.top) * (canvas.height / r.height) };
        };

        canvas.addEventListener('pointerdown', (e) => {
            this.drawing = true;
            canvas.setPointerCapture(e.pointerId);
            const p = point(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        });
        canvas.addEventListener('pointermove', (e) => {
            if (!this.drawing) return;
            const p = point(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            this.empty = false;
        });
        const stop = () => { this.drawing = false; };
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointerleave', stop);
    },

    clear() {
        const canvas = this.$refs.canvas;
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        this.empty = true;
    },

    capture(event) {
        if (this.empty) {
            event.preventDefault();
            alert('Draw your signature in the box before signing.');
            return;
        }
        this.$refs.data.value = this.$refs.canvas.toDataURL('image/png');
    },
}));

/*
 * Shows an uploaded Word report on the page. The file is fetched from the preview
 * route and drawn by docx-preview, loaded only when a Word file is on screen.
 * PDFs need none of this: the browser's own viewer shows them in an iframe.
 */
Alpine.data('docxPreview', (url) => ({
    state: 'loading',

    async init() {
        try {
            const [{ renderAsync }, response] = await Promise.all([import('docx-preview'), fetch(url, { credentials: 'same-origin' })]);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            await renderAsync(await response.blob(), this.$refs.page, null, {
                className: 'docx',
                inWrapper: true,
                ignoreLastRenderedPageBreak: true,
                breakPages: true,
            });
            this.state = 'ready';
        } catch (e) {
            console.error('Word preview failed', e);
            this.state = 'failed';
        }
    },
}));

/*
 * The page frame: the menu on the left and the viewer's display choices, remembered
 * on this device (partials/display-preferences applies them before the page is drawn).
 *
 * Wide screens: the toggle hides or shows the menu, and its edge can be dragged (or
 * moved with the arrow keys) between 13rem and a quarter of the window.
 * Small screens: the toggle opens the menu over the page.
 */
const MIN_WIDTH = 208;
const store = {
    get(key) {
        try { return localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
        try { value === null ? localStorage.removeItem(key) : localStorage.setItem(key, value); } catch { /* storage blocked */ }
    },
};

Alpine.data('layout', () => ({
    menu: false,
    collapsed: document.documentElement.dataset.sidebar === 'collapsed',
    width: parseInt(getComputedStyle(document.documentElement).getPropertyValue('--sidebar-w'), 10) || 240,
    textSize: document.documentElement.dataset.textSize || 'normal',

    wide() {
        return window.matchMedia('(min-width: 64rem)').matches;
    },

    maxWidth() {
        return Math.floor(window.innerWidth / 4);
    },

    sidebarOpen() {
        return this.wide() ? !this.collapsed : this.menu;
    },

    toggleSidebar() {
        if (!this.wide()) {
            this.menu = !this.menu;
            return;
        }
        this.collapsed = !this.collapsed;
        if (this.collapsed) {
            document.documentElement.dataset.sidebar = 'collapsed';
        } else {
            delete document.documentElement.dataset.sidebar;
        }
        store.set('oha.sidebar', this.collapsed ? 'collapsed' : null);
    },

    setWidth(px, save = true) {
        this.width = Math.round(Math.min(Math.max(px, MIN_WIDTH), this.maxWidth()));
        document.documentElement.style.setProperty('--sidebar-w', this.width + 'px');
        if (save) store.set('oha.sidebarWidth', String(this.width));
    },

    resetWidth() {
        this.width = 240;
        document.documentElement.style.removeProperty('--sidebar-w');
        store.set('oha.sidebarWidth', null);
    },

    startResize(event) {
        event.preventDefault();
        const handle = event.currentTarget;
        handle.setPointerCapture(event.pointerId);
        document.body.style.userSelect = 'none';
        const move = (e) => this.setWidth(e.clientX, false);
        const stop = () => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', stop);
            document.body.style.userSelect = '';
            store.set('oha.sidebarWidth', String(this.width));
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', stop);
    },

    keyResize(event) {
        const steps = { ArrowLeft: -16, ArrowRight: 16 };
        if (event.key in steps) {
            event.preventDefault();
            this.setWidth(this.width + steps[event.key]);
        } else if (event.key === 'Home') {
            event.preventDefault();
            this.setWidth(MIN_WIDTH);
        } else if (event.key === 'End') {
            event.preventDefault();
            this.setWidth(this.maxWidth());
        }
    },

    setTextSize(size) {
        this.textSize = size;
        if (size === 'normal') {
            delete document.documentElement.dataset.textSize;
        } else {
            document.documentElement.dataset.textSize = size;
        }
        store.set('oha.textSize', size === 'normal' ? null : size);
    },
}));

window.Alpine = Alpine;
Alpine.start();
