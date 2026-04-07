document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('newsletterActionModal');
    const contentElement = document.getElementById('newsletterActionContent');
    const titleElement = document.getElementById('newsletterActionModalLabel');

    if (!modalElement || !contentElement || !titleElement || typeof bootstrap === 'undefined') {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);
    let currentUrl = '';
    let isLoading = false;

    function withModalFlag(url) {
        try {
            const next = new URL(url, window.location.origin);
            if (!next.searchParams.has('modal')) {
                next.searchParams.set('modal', '1');
            }
            return next.pathname + next.search + next.hash;
        } catch (_error) {
            return url;
        }
    }

    function executeInlineScripts(container) {
        const scripts = Array.from(container.querySelectorAll('script'));
        scripts.forEach(function (script) {
            if (script.src) {
                return;
            }

            const code = script.textContent || '';
            if (!code.trim()) {
                script.remove();
                return;
            }

            try {
                // Run inline scripts in a function scope to avoid global redeclaration errors.
                const runner = new Function(code);
                runner();
            } catch (error) {
                console.error('Newsletter modal script error:', error);
            }

            script.remove();
        });
    }

    function initTinymceInModal() {
        if (typeof window.initTinymceEditors === 'function') {
            window.initTinymceEditors(contentElement);
        }
    }

    function cleanupTinymceInModal() {
        if (typeof tinymce === 'undefined' || !tinymce.editors) {
            return;
        }

        const editors = tinymce.editors.slice();
        editors.forEach(function (editor) {
            const target = editor && editor.targetElm;
            if (target && contentElement.contains(target)) {
                editor.remove();
            }
        });
    }

    async function loadModalContent(url, title) {
        if (!url || isLoading) {
            return;
        }

        const modalUrl = withModalFlag(String(url));
        currentUrl = modalUrl;
        titleElement.textContent = title && String(title).trim() ? String(title).trim() : 'Newsletter';
        contentElement.innerHTML = '<div class="p-3 text-muted">Lade Inhalt...</div>';
        modal.show();
        isLoading = true;

        try {
            const response = await fetch(modalUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const body = parsed.querySelector('body');

            cleanupTinymceInModal();
            contentElement.innerHTML = body ? body.innerHTML : html;
            executeInlineScripts(contentElement);
            initTinymceInModal();
        } catch (error) {
            contentElement.innerHTML = '<div class="alert alert-danger m-3 mb-0">Inhalt konnte nicht geladen werden.</div>';
            console.error('Newsletter modal load error:', error);
        } finally {
            isLoading = false;
        }
    }

    function bindTriggerButtons() {
        document.querySelectorAll('[data-newsletter-modal-url]').forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                const url = trigger.getAttribute('data-newsletter-modal-url') || '';
                const title = trigger.getAttribute('data-newsletter-modal-title') || 'Newsletter';
                loadModalContent(url, title);
            });
        });
    }

    function isNewsletterOverviewPath(pathname) {
        return pathname === '/newsletters' || pathname === '/newsletters/';
    }

    contentElement.addEventListener('click', function (event) {
        const modalTrigger = event.target.closest('[data-newsletter-modal-url]');
        if (modalTrigger) {
            event.preventDefault();
            const url = modalTrigger.getAttribute('data-newsletter-modal-url') || '';
            const title = modalTrigger.getAttribute('data-newsletter-modal-title') || 'Newsletter';
            loadModalContent(url, title);
            return;
        }

        const link = event.target.closest('a[href]');
        if (!link) {
            return;
        }

        const href = link.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
            return;
        }

        if (href.startsWith('/newsletters')) {
            event.preventDefault();
            const parsed = new URL(href, window.location.origin);

            if (isNewsletterOverviewPath(parsed.pathname)) {
                window.newsletterModalCloseAndRefresh();
                return;
            }

            loadModalContent(href, titleElement.textContent);
        }
    });

    window.newsletterModalNavigate = function (url, title) {
        loadModalContent(url, title || titleElement.textContent || 'Newsletter');
    };

    window.newsletterModalCloseAndRefresh = function () {
        modal.hide();
        window.location.reload();
    };

    modalElement.addEventListener('hidden.bs.modal', function () {
        cleanupTinymceInModal();
        currentUrl = '';
        contentElement.innerHTML = '';
    });

    bindTriggerButtons();
});
