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

    async function executeExternalScripts(container) {
        const scripts = Array.from(container.querySelectorAll('script[src]'));

        for (const script of scripts) {
            const src = script.getAttribute('src') || '';
            script.remove();

            if (!src) {
                continue;
            }

            await new Promise(function (resolve) {
                const dynamicScript = document.createElement('script');
                dynamicScript.src = src;
                dynamicScript.async = false;

                const type = script.getAttribute('type');
                if (type) {
                    dynamicScript.type = type;
                }

                if (script.hasAttribute('nomodule')) {
                    dynamicScript.setAttribute('nomodule', '');
                }

                dynamicScript.onload = function () {
                    dynamicScript.remove();
                    resolve();
                };

                dynamicScript.onerror = function () {
                    console.error('Newsletter modal script load error:', src);
                    dynamicScript.remove();
                    resolve();
                };

                document.body.appendChild(dynamicScript);
            });
        }
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

    function showModalAlert(type, message) {
        if (!message) {
            return;
        }

        contentElement.querySelectorAll('.newsletter-modal-alert').forEach(function (el) {
            el.remove();
        });

        const wrapper = document.createElement('div');
        wrapper.className = 'alert alert-' + type + ' alert-dismissible fade show newsletter-modal-alert m-2';
        wrapper.setAttribute('role', 'alert');
        wrapper.textContent = String(message);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('data-bs-dismiss', 'alert');
        closeBtn.setAttribute('aria-label', 'Close');
        wrapper.appendChild(closeBtn);

        contentElement.prepend(wrapper);
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
            await executeExternalScripts(contentElement);
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

    function bindActionDropdownLayering() {
        const table = document.getElementById('newslettersTable');
        if (!table) {
            return;
        }

        const toggles = table.querySelectorAll('[data-bs-toggle="dropdown"]');
        toggles.forEach(function (toggle) {
            if (!(toggle instanceof HTMLElement)) {
                return;
            }
            if (toggle.getAttribute('data-newsletter-dropdown-init') === '1') {
                return;
            }

            toggle.setAttribute('data-newsletter-dropdown-init', '1');

            // Use fixed strategy so dropdowns are positioned against viewport and are not clipped by table wrappers.
            new bootstrap.Dropdown(toggle, {
                boundary: 'viewport',
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, {
                        strategy: 'fixed',
                    });
                },
            });
        });

        table.addEventListener('show.bs.dropdown', function (event) {
            const dropdown = event.target instanceof Element ? event.target : null;
            const row = dropdown ? dropdown.closest('tr') : null;
            if (row) {
                row.classList.add('newsletter-dropdown-open');
            }
        });

        table.addEventListener('hide.bs.dropdown', function (event) {
            const dropdown = event.target instanceof Element ? event.target : null;
            const row = dropdown ? dropdown.closest('tr') : null;
            if (row) {
                row.classList.remove('newsletter-dropdown-open');
            }
        });
    }

    function isNewsletterOverviewPath(pathname) {
        return pathname === '/newsletters'
            || pathname === '/newsletters/'
            || pathname === '/newsletters/templates'
            || pathname === '/newsletters/templates/';
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

    contentElement.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const action = form.getAttribute('action') || '';
        const isCreateForm = action === '/newsletters' || action === '/newsletters/';
        const isTemplateForm = action.startsWith('/newsletters/templates/');
        if (!isCreateForm && !isTemplateForm) {
            return;
        }

        event.preventDefault();

        const method = (form.getAttribute('method') || 'POST').toUpperCase();
        const formData = new FormData(form);

        // For the newsletter create form, pull content from TinyMCE before submitting
        if (isCreateForm) {
            const editor = typeof tinymce !== 'undefined' ? tinymce.get('content_html') : null;
            if (editor) {
                formData.set('content_html', editor.getContent());
            }
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        if (csrfToken && !formData.has('_csrf')) {
            formData.append('_csrf', csrfToken);
        }

        try {
            const response = await fetch(action, {
                method,
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
                },
                credentials: 'same-origin',
            });
            const responseType = response.headers.get('Content-Type') || '';
            let payload = null;
            if (responseType.includes('application/json')) {
                payload = await response.json();
            }

            if (!response.ok) {
                const errorMessage = payload && payload.error
                    ? String(payload.error)
                    : 'Speichern fehlgeschlagen.';
                showModalAlert('danger', errorMessage);
                return;
            }

            if (payload && payload.redirect) {
                loadModalContent(payload.redirect, titleElement.textContent || 'Newsletter');
                return;
            }

            if (!payload || !payload.success) {
                const html = await response.text();
                if (html && html.trim()) {
                    contentElement.innerHTML = html;
                    executeInlineScripts(contentElement);
                    await executeExternalScripts(contentElement);
                    initTinymceInModal();
                    return;
                }
            }

            window.newsletterModalCloseAndRefresh();
        } catch (error) {
            showModalAlert('danger', 'Vorlage konnte nicht gespeichert werden.');
            console.error('Newsletter template modal submit error:', error);
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
    bindActionDropdownLayering();
});
