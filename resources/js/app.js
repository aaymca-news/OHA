import Alpine from 'alpinejs';

/*
 * Choosing a profile photo: it is shown at once in the round frame it will appear in,
 * to be dragged into place and zoomed. On saving, the framed square is what is sent
 * (512 pixels, JPEG); the server makes the final 256-pixel copy. Without scripts the
 * chosen file is sent as it is, and the server crops it around the centre.
 */
Alpine.data('photoCropper', () => ({
    src: null,
    image: null,
    zoom: 1,
    x: 0,
    y: 0,
    dragging: null,
    problem: '',

    get frame() {
        return this.$refs.frame?.clientWidth || 224;
    },

    /** The smallest scale at which the photo still fills the frame. */
    get cover() {
        return this.image ? this.frame / Math.min(this.image.naturalWidth, this.image.naturalHeight) : 1;
    },

    get style() {
        if (!this.image) return '';
        const s = this.cover * this.zoom;
        return `width:${this.image.naturalWidth * s}px;height:${this.image.naturalHeight * s}px;transform:translate(${this.x}px,${this.y}px)`;
    },

    choose(event) {
        const file = event.target.files[0];
        this.problem = '';
        if (this.src) URL.revokeObjectURL(this.src);
        this.src = null;
        this.image = null;
        if (!file) return;
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            this.problem = 'Choose a JPG, PNG or WebP photo.';
            return;
        }
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            this.image = img;
            this.zoom = 1;
            // Measure the frame once it is on screen: its size follows the chosen text size.
            this.$nextTick(() => this.centre());
        };
        img.onerror = () => { this.problem = 'This image could not be read. Use a JPG or PNG photo.'; };
        img.src = url;
        this.src = url;
    },

    centre() {
        const s = this.cover * this.zoom;
        this.x = (this.frame - this.image.naturalWidth * s) / 2;
        this.y = (this.frame - this.image.naturalHeight * s) / 2;
    },

    /** Keeps the frame filled: the photo's edges never come inside it. */
    clamp() {
        const s = this.cover * this.zoom;
        this.x = Math.min(0, Math.max(this.frame - this.image.naturalWidth * s, this.x));
        this.y = Math.min(0, Math.max(this.frame - this.image.naturalHeight * s, this.y));
    },

    setZoom(value) {
        const before = this.cover * this.zoom;
        const centre = this.frame / 2;
        this.zoom = Number(value);
        const after = this.cover * this.zoom;
        // Zoom around the middle of the frame, so what is centred stays centred.
        this.x = centre - ((centre - this.x) * after) / before;
        this.y = centre - ((centre - this.y) * after) / before;
        this.clamp();
    },

    start(event) {
        if (!this.image) return;
        event.target.setPointerCapture?.(event.pointerId);
        this.dragging = { px: event.clientX, py: event.clientY, x: this.x, y: this.y };
    },

    move(event) {
        if (!this.dragging) return;
        this.x = this.dragging.x + event.clientX - this.dragging.px;
        this.y = this.dragging.y + event.clientY - this.dragging.py;
        this.clamp();
    },

    stop() {
        this.dragging = null;
    },

    /** Arrow keys move the photo; + and − zoom. */
    key(event) {
        if (!this.image) return;
        const step = event.shiftKey ? 20 : 5;
        const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
        if (moves[event.key]) {
            event.preventDefault();
            this.x += moves[event.key][0];
            this.y += moves[event.key][1];
            this.clamp();
        } else if (event.key === '+' || event.key === '=') {
            this.setZoom(Math.min(4, this.zoom + 0.1));
        } else if (event.key === '-') {
            this.setZoom(Math.max(1, this.zoom - 0.1));
        }
    },

    /** Replaces the chosen file with the framed square before the form is sent. */
    async save(event) {
        if (!this.image) return;
        event.preventDefault();
        const form = event.target;
        const size = 512;
        const s = this.cover * this.zoom;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.drawImage(this.image, -this.x / s, -this.y / s, this.frame / s, this.frame / s, 0, 0, size, size);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        if (blob) {
            const files = new DataTransfer();
            files.items.add(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
            this.$refs.file.files = files.files;
        }
        form.submit();
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
    textScale: parseInt(document.documentElement.style.fontSize, 10) || 100,

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

    // Text size as a percentage of the reader's own browser setting; 100 is unchanged.
    setTextScale(value) {
        this.textScale = Math.min(140, Math.max(90, Math.round(value / 5) * 5));
        document.documentElement.style.fontSize = this.textScale === 100 ? '' : this.textScale + '%';
        store.set('oha.textScale', this.textScale === 100 ? null : String(this.textScale));
        store.set('oha.textSize', null);
    },
}));

/*
 * A box a file is dropped on or chosen in (the OHA form, the report, the ODP). The file
 * dropped is put in the form's own file input, so it is sent like a chosen one; its type
 * and size are checked at once, and anything wrong is said beside the box, not in a
 * browser bubble on an input nobody can see. A form whose file is missing says so too.
 */
Alpine.data('fileDrop', ({ accept, maxKb, required, label }) => ({
    over: false,
    name: '',
    problem: '',

    init() {
        this.$el.closest('form')?.addEventListener('submit', (event) => {
            if (required && !this.$refs.input.files.length) {
                event.preventDefault();
                this.problem = `Choose the ${label} first, or drop it in the box.`;
            }
        });
    },

    drop(event) {
        this.over = false;
        const file = event.dataTransfer?.files?.[0];
        if (!file) {
            return;
        }
        const files = new DataTransfer();
        files.items.add(file);
        this.$refs.input.files = files.files;
        this.check();
    },

    check() {
        const file = this.$refs.input.files[0];
        this.name = '';
        this.problem = '';
        if (!file) {
            return;
        }
        const allowed = accept.split(',').map((type) => type.trim().toLowerCase());
        const type = '.' + file.name.split('.').pop().toLowerCase();
        if (!allowed.includes(type)) {
            this.problem = `“${file.name}” is not a ${allowed.join(', ')} file. Choose the ${label} in one of those formats.`;
        } else if (file.size > maxKb * 1024) {
            const mb = (n) => (Math.round((n / 1024) * 10) / 10).toString();
            this.problem = `“${file.name}” is ${mb(file.size / 1024)} MB; the most that can be uploaded is ${mb(maxKb)} MB. Make it smaller (a scanned PDF saved at a lower quality, for example).`;
        } else {
            this.name = file.name;

            return;
        }
        this.$refs.input.value = '';
    },
}));

/*
 * The ODP's Google Drive link, checked as it is pasted, so a folder link is caught before
 * the form is sent (and the file chosen with it is not lost). The server checks it again.
 */
Alpine.data('driveLink', (initial = '') => ({
    link: initial,

    get problem() {
        const url = this.link.trim();
        if (url === '') {
            return '';
        }
        if (!/^https:\/\/(docs|drive)\.google\.com\//i.test(url)) {
            return 'Paste the link to the ODP in Google Drive: it starts with https://docs.google.com/ or https://drive.google.com/.';
        }
        if (/\/folders\//i.test(url)) {
            return 'This is a link to a folder (such as the movement’s folder in Google Drive), not to the ODP. Open the folder, open the ODP file inside it, then copy its link with Share → Copy link.';
        }
        if (/\/(presentation|forms)\//i.test(url)) {
            return 'This is a link to Google Slides or Forms. The ODP is a spreadsheet or a document: link the Google Sheet, the Google Doc, or the Excel or Word file in Drive.';
        }

        return '';
    },

    init() {
        this.$el.closest('form')?.addEventListener('submit', (event) => {
            if (this.problem) {
                event.preventDefault();
                this.$el.querySelector('input')?.focus();
            }
        });
    },
}));

/*
 * A file dropped beside a drop box would otherwise be opened by the browser, leaving the
 * page and what was typed on it. Only a file input itself takes a drop outside a box.
 */
for (const type of ['dragover', 'drop']) {
    window.addEventListener(type, (event) => {
        if (event.dataTransfer?.types?.includes('Files') && !(event.target instanceof HTMLInputElement && event.target.type === 'file')) {
            event.preventDefault();
        }
    });
}

window.Alpine = Alpine;
Alpine.start();
