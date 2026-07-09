/*!
 * QRF web-form connector — drop-in vanilla JS (spec 15 § C4).
 *
 * Use:
 *   <form data-qrf-form
 *         data-endpoint="https://crm.qrf.vn/api/public/ingest/contact"
 *         data-token="whk_a1b2c3..."
 *         data-source-detail="summer-sale-2026">
 *     <input name="full_name" required>
 *     <input name="phone">
 *     <input name="email">
 *     <textarea name="message"></textarea>   <!-- becomes attributes.message -->
 *     <input name="utm_source">                <!-- becomes attributes.utm_* -->
 *     <input name="utm_campaign">
 *     <button>Gửi</button>
 *   </form>
 *   <template data-success-template><p>Cảm ơn! Chúng tôi sẽ liên hệ sớm.</p></template>
 *   <template data-error-template><p>Có lỗi xảy ra, vui lòng thử lại.</p></template>
 *   <script src="/path/to/qrf-web-form.js" defer></script>
 *
 * Behavior:
 *   - source_event_id is generated ONCE per page load (crypto.randomUUID).
 *     Re-submitting the form reuses the same id, so the server treats it as
 *     a dedup retry (returns 200, no duplicate contact).
 *   - Server context (page_url, referrer, user_agent, ip, received_at) is
 *     captured server-side into attributes.
 *   - All `data-attribute="<name>"` field values are merged into attributes.
 *   - All other named fields (full_name, phone, email, external_identity, ...)
 *     pass through verbatim.
 *
 * No build step, no framework, no dependencies. ~3 KB minified.
 */
(function () {
    'use strict';

    if (typeof document === 'undefined') {
        return;
    }

    /**
     * Initialize a single form. Idempotent — safe to call on the same form
     * twice (the second call is a no-op).
     */
    function initForm(form) {
        if (form.__qrfForm) {
return;
}

        form.__qrfForm = true;

        var endpoint = form.getAttribute('data-endpoint');
        var token = form.getAttribute('data-token');

        if (!endpoint || !token) {
            console.warn('[qrf-web-form] missing data-endpoint or data-token', form);

            return;
        }

        // Persist for the lifetime of the page so retries dedup against the
        // original submission (the server's contact_ingest_events table
        // keys on this id).
        var sourceEventId = (function () {
            try {
                return crypto.randomUUID();
            } catch {
                // Safari < 15.4 fallback — not cryptographically random but
                // sufficient as a per-page dedup key.
                return 'evt-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
            }
        })();

        var sourceDetail = form.getAttribute('data-source-detail') || null;

        var successEl = findTemplate(form, 'data-success-template');
        var errorEl = findTemplate(form, 'data-error-template');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitForm({
                form: form,
                endpoint: endpoint,
                token: token,
                sourceEventId: sourceEventId,
                sourceDetail: sourceDetail,
                successEl: successEl,
                errorEl: errorEl,
            });
        });
    }

    /**
     * Look up a <template data-X-template> element. Falls back to any element
     * with the same data-attr (template is just the canonical shape).
     */
    function findTemplate(form, attr) {
        // Same scope first — the template is usually right next to the form.
        var scope = form.parentElement || document.body;
        var el = scope.querySelector('template[' + attr + ']');

        if (el) {
return el;
}

        el = scope.querySelector('[' + attr + ']');

        return el;
    }

    /**
     * Build the JSON payload from form fields + data-attribute children.
     */
    function buildPayload(form) {
        var body = {};
        var attributes = {};
        var consent = null;
        var externalIdentity = null;

        // Iterate declared form elements (skips buttons, etc.).
        var elements = form.elements;

        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];

            if (!el.name || el.disabled) {
continue;
}

            if (el.type === 'submit' || el.type === 'button') {
continue;
}

            var value = readValue(el);

            if (value === '' || value === null) {
continue;
}

            switch (el.name) {
                case 'full_name':
                case 'phone':
                case 'email':
                    body[el.name] = value;
                    break;
                case 'consent_text':
                case 'consent_given_at':
                    if (!consent) {
consent = {};
}

                    consent[el.name.replace(/^consent_/, '')] = value;
                    break;
                case 'external_identity_provider':
                case 'external_identity_provider_account_id':
                case 'external_identity_provider_user_id':
                case 'external_identity_display_name':
                case 'external_identity_avatar_url':
                    if (!externalIdentity) {
externalIdentity = {};
}

                    externalIdentity[el.name.replace(/^external_identity_/, '')] = value;
                    break;
                default:
                    // Anything else goes into attributes — including UTM
                    // (utm_source/utm_campaign/utm_medium) and message.
                    attributes[el.name] = value;
            }
        }

        if (Object.keys(attributes).length) {
body.attributes = attributes;
}

        if (consent) {
body.consent = consent;
}

        if (externalIdentity) {
body.external_identity = externalIdentity;
}

        return body;
    }

    function readValue(el) {
        if (el.type === 'checkbox') {
return el.checked ? 'true' : '';
}

        if (el.type === 'radio') {
return el.checked ? el.value : '';
}

        return (el.value || '').trim();
    }

    /**
     * POST to the endpoint with the right headers + body, then handle the
     * response: 200/201 → success template, otherwise → error template.
     */
    function submitForm(opts) {
        var form = opts.form;
        var payload = buildPayload(form);
        var submitButton = form.querySelector('button[type="submit"], button:not([type])');

        if (submitButton) {
submitButton.disabled = true;
}

        var headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Workspace-Key': opts.token,
            'X-Source': 'WEBSITE_FORM',
            'X-Source-Event-Id': opts.sourceEventId,
        };

        if (opts.sourceDetail) {
            headers['X-Source-Detail'] = opts.sourceDetail;
        }

        fetch(opts.endpoint, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload),
            credentials: 'omit',
            keepalive: true,
        })
            .then(function (response) {
                // 200 / 201 are both success (201 = created, 200 = dedup retry).
                if (response.status === 200 || response.status === 201) {
                    showSuccess(form, opts.successEl, payload);
                } else {
                    showError(form, opts.errorEl, response.status);
                }
            })
            .catch(function (networkErr) {
                showError(form, opts.errorEl, 0, networkErr);
            })
            .then(function () {
                if (submitButton) {
submitButton.disabled = false;
}
            });
    }

    function showSuccess(form, template, payload) {
        // Hide form so the user knows we're done; cloning the template
        // content (templates need to be cloned to be inserted).
        form.style.display = 'none';

        if (template) {
            var node = template.content
                ? template.content.cloneNode(true)
                : template.cloneNode(true);
            (form.parentNode || document.body).appendChild(node);
        }

        // Emit a CustomEvent so page-level scripts can react (e.g. trigger
        // analytics, fire a thank-you redirect, etc.).
        form.dispatchEvent(new CustomEvent('qrf:submitted', {
            bubbles: true,
            detail: { ok: true, payload: payload },
        }));
    }

    function showError(form, template, status, networkErr) {
        if (template) {
            var existing = form.parentNode.querySelector('[data-qrf-error]');

            if (existing) {
existing.remove();
}

            var node = template.content
                ? template.content.cloneNode(true)
                : template.cloneNode(true);
            node.setAttribute('data-qrf-error', '');
            form.parentNode.insertBefore(node, form.nextSibling);
        }

        form.dispatchEvent(new CustomEvent('qrf:error', {
            bubbles: true,
            detail: { status: status, error: networkErr },
        }));
    }

    // Auto-init when the DOM is ready. Use capture to find forms added later
    // (rare, but defensive against single-page apps injecting forms).
    function scan(root) {
        var forms = (root || document).querySelectorAll('[data-qrf-form]');

        for (var i = 0; i < forms.length; i++) {
initForm(forms[i]);
}
    }

    function ready() {
        scan(document);

        // Re-scan on DOM mutations (light observer; pages rarely add forms
        // dynamically but we want the connector to be robust).
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;

                    for (var j = 0; j < added.length; j++) {
                        var n = added[j];

                        if (n.nodeType !== 1) {
continue;
}

                        if (n.matches && n.matches('[data-qrf-form]')) {
initForm(n);
}

                        if (n.querySelectorAll) {
scan(n);
}
                    }
                }
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();