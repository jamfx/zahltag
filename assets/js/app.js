'use strict';

/* ================================================================
   Zahltag – app.js
   Vanilla JS, kein Framework
   ================================================================ */

/* ── Flash Messages: Auto-Close ── */

document.querySelectorAll('.flash').forEach(function (el) {
    var btn = el.querySelector('.flash__close');
    if (btn) {
        btn.addEventListener('click', function () { closeFlash(el); });
    }
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setTimeout(function () { closeFlash(el); }, reducedMotion ? 15000 : 10000);
});

function closeFlash(el) {
    el.style.transition = 'opacity .35s, margin-top .35s, padding .35s, max-height .35s';
    el.style.opacity = '0';
    el.style.maxHeight = el.offsetHeight + 'px';
    requestAnimationFrame(function () {
        el.style.maxHeight = '0';
        el.style.marginBottom = '0';
        el.style.paddingTop = '0';
        el.style.paddingBottom = '0';
        el.style.borderWidth = '0';
    });
    setTimeout(function () { el.remove(); }, 380);
}

/* ── Mobile Navigation Toggle ── */

(function () {
    var toggle   = document.querySelector('.nav-toggle');
    var nav      = document.querySelector('.site-nav');
    var backdrop = document.getElementById('nav-backdrop');
    if (!toggle || !nav) return;

    function openNav() {
        nav.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
    }
    function closeNav() {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
        nav.classList.contains('is-open') ? closeNav() : openNav();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && nav.classList.contains('is-open')) {
            closeNav();
            toggle.focus();
        }
    });
})();

/* ── Nav dropdown: close when clicking outside ── */
document.addEventListener('click', function (e) {
    document.querySelectorAll('.nav-dropdown[open]').forEach(function (d) {
        if (!d.contains(e.target)) d.removeAttribute('open');
    });
});

/* ── Focus-Trap-Hilfsfunktion ── */

function trapFocus(container) {
    var sel = 'a[href], button:not([disabled]), input:not([disabled]), ' +
              'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    function handler(e) {
        if (e.key !== 'Tab') return;
        var focusable = Array.prototype.slice.call(container.querySelectorAll(sel));
        if (!focusable.length) return;
        var first = focusable[0];
        var last  = focusable[focusable.length - 1];
        if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
        }
    }
    container.addEventListener('keydown', handler);
    return function () { container.removeEventListener('keydown', handler); };
}

/* ── Confirm-Modal ── */

var _cm = null;

function _cmBuild() {
    if (_cm) return _cm;
    var cancelLabel = (document.body.dataset.cmCancel) || 'Abbrechen';
    var okLabel     = (document.body.dataset.cmOk)     || 'OK';
    var wrap = document.createElement('div');
    wrap.className = 'modal-backdrop';
    wrap.style.display = 'none';
    wrap.innerHTML =
        '<div class="modal" role="alertdialog" aria-modal="true" aria-describedby="cm-msg">' +
            '<p id="cm-msg" style="margin-bottom:0"></p>' +
            '<div class="modal__actions">' +
                '<button id="cm-cancel" class="btn btn--ghost" type="button">' + cancelLabel + '</button>' +
                '<button id="cm-ok" class="btn btn--primary" type="button">' + okLabel + '</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(wrap);
    _cm = wrap;
    return wrap;
}

function showConfirm(msg, onOk, isDanger) {
    var wrap      = _cmBuild();
    var okBtn     = wrap.querySelector('#cm-ok');
    var cancelBtn = wrap.querySelector('#cm-cancel');
    var trigger   = document.activeElement;
    wrap.querySelector('#cm-msg').textContent = msg;
    okBtn.className = 'btn ' + (isDanger ? 'btn--danger' : 'btn--primary');
    wrap.style.display = 'flex';

    var removeTrap = trapFocus(wrap.querySelector('.modal'));

    function cleanup() {
        wrap.style.display = 'none';
        removeTrap();
        okBtn.removeEventListener('click', onYes);
        cancelBtn.removeEventListener('click', onNo);
        wrap.removeEventListener('click', onBg);
        document.removeEventListener('keydown', onKey);
        if (trigger && trigger.focus) trigger.focus();
    }
    function onYes() { cleanup(); if (onOk) onOk(); }
    function onNo()  { cleanup(); }
    function onBg(e) { if (e.target === wrap) cleanup(); }
    function onKey(e) { if (e.key === 'Escape') cleanup(); }

    okBtn.addEventListener('click', onYes);
    cancelBtn.addEventListener('click', onNo);
    wrap.addEventListener('click', onBg);
    document.addEventListener('keydown', onKey);
    setTimeout(function () { cancelBtn.focus(); }, 0);
}

/* ── Bestätigungsdialoge (data-confirm) ── */

document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el) return;
    var msg = el.dataset.confirm;
    e.preventDefault();
    e.stopImmediatePropagation();
    var isDanger = el.classList.contains('btn--danger') ||
                   el.classList.contains('dash-actions-menu__form-btn--danger');
    showConfirm(msg, function () {
        el.removeAttribute('data-confirm');
        el.click();
    }, isDanger);
});

/* ── Copy to Clipboard (data-copy / data-copy-target) ── */

document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy], [data-copy-target]');
    if (!btn) return;

    var text = btn.dataset.copy;
    if (!text && btn.dataset.copyTarget) {
        var src = document.querySelector(btn.dataset.copyTarget);
        text = src ? (src.value || src.textContent) : '';
    }
    if (!text) return;

    navigator.clipboard.writeText(text.trim()).then(function () {
        var orig = btn.textContent;
        var label = btn.dataset.copiedLabel || 'Kopiert!';
        btn.textContent = label;
        btn.disabled = true;
        setTimeout(function () {
            btn.textContent = orig;
            btn.disabled = false;
        }, 2000);
    }).catch(function () {
        // Fallback for older browsers
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    });
});

/* ── Lightbox für Beleg-Bilder ── */

document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-lightbox]');
    if (!trigger) return;

    var src = trigger.dataset.lightbox || trigger.href;
    if (!src) return;
    e.preventDefault();

    var backdrop = document.createElement('div');
    backdrop.className = 'lightbox-backdrop';
    backdrop.setAttribute('role', 'dialog');
    backdrop.setAttribute('aria-modal', 'true');

    var img = document.createElement('img');
    img.className = 'lightbox-img';
    img.src = src;
    img.alt = trigger.dataset.lightboxAlt || '';

    backdrop.appendChild(img);
    document.body.appendChild(backdrop);
    document.body.style.overflow = 'hidden';

    function closeLightbox() {
        backdrop.remove();
        document.body.style.overflow = '';
    }

    backdrop.addEventListener('click', function (ev) {
        if (ev.target === backdrop) closeLightbox();
    });

    document.addEventListener('keydown', function handler(ev) {
        if (ev.key === 'Escape') {
            closeLightbox();
            document.removeEventListener('keydown', handler);
        }
    });
});

/* ── Client-seitige Formular-Validierung ── */

(function () {

    // Required fields (data-required)
    // Number range (data-min, data-max)
    // Min length (data-minlength)
    // Numeric check (data-numeric)
    // Equal to another field (data-equal-to="#other-id")

    function showError(field, msg) {
        var wrapper = field.closest('.form-group') || field.parentElement;
        field.classList.add('is-invalid');
        var existing = wrapper.querySelector('.field-error[data-js-error]');
        if (existing) { existing.textContent = msg; return; }
        var err = document.createElement('span');
        err.className = 'field-error';
        err.setAttribute('data-js-error', '1');
        err.setAttribute('role', 'alert');
        err.textContent = msg;
        field.insertAdjacentElement('afterend', err);
    }

    function clearError(field) {
        field.classList.remove('is-invalid');
        var wrapper = field.closest('.form-group') || field.parentElement;
        var err = wrapper.querySelector('.field-error[data-js-error]');
        if (err) err.remove();
    }

    function validateField(field) {
        var value = field.value.trim();

        // required
        if (field.dataset.required !== undefined && value === '') {
            showError(field, field.dataset.requiredMsg || 'Pflichtfeld.');
            return false;
        }

        // minlength
        if (field.dataset.minlength && value.length < parseInt(field.dataset.minlength)) {
            showError(field, field.dataset.minlengthMsg || 'Zu kurz.');
            return false;
        }

        // numeric
        if (field.dataset.numeric !== undefined && value !== '' && isNaN(parseFloat(value.replace(',', '.')))) {
            showError(field, field.dataset.numericMsg || 'Bitte eine Zahl eingeben.');
            return false;
        }

        // min/max for numbers
        var num = parseFloat(value.replace(',', '.'));
        if (field.dataset.min !== undefined && !isNaN(num) && num < parseFloat(field.dataset.min)) {
            showError(field, field.dataset.minMsg || 'Wert zu klein.');
            return false;
        }
        if (field.dataset.max !== undefined && !isNaN(num) && num > parseFloat(field.dataset.max)) {
            showError(field, field.dataset.maxMsg || 'Wert zu groß.');
            return false;
        }

        // equal-to (password confirm)
        if (field.dataset.equalTo) {
            var other = document.querySelector(field.dataset.equalTo);
            if (other && field.value !== other.value) {
                showError(field, field.dataset.equalToMsg || 'Felder stimmen nicht überein.');
                return false;
            }
        }

        clearError(field);
        return true;
    }

    // Validate on blur
    document.addEventListener('focusout', function (e) {
        var field = e.target;
        if (!field.matches('input,select,textarea')) return;
        if (field.closest('[data-no-validate]')) return;
        if (field.dataset.required !== undefined || field.dataset.minlength || field.dataset.numeric !== undefined || field.dataset.equalTo) {
            validateField(field);
        }
    });

    // Validate on form submit
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.dataset.noValidate !== undefined) return;

        var valid = true;
        var fields = form.querySelectorAll('input[data-required],select[data-required],textarea[data-required],input[data-numeric],input[data-equal-to]');
        fields.forEach(function (field) {
            if (!validateField(field)) valid = false;
        });

        if (!valid) {
            e.preventDefault();
            var first = form.querySelector('.is-invalid');
            if (first) first.focus();
        }
    });

})();

/* ── Split-Betrag Summenprüfung ── */

(function () {
    var splitContainer = document.querySelector('[data-split-container]');
    if (!splitContainer) return;

    var totalEl = document.querySelector('[data-split-total]');
    var sumEl   = document.querySelector('[data-split-sum]');
    if (!totalEl || !sumEl) return;

    function updateSum() {
        var total = parseFloat(totalEl.dataset.splitTotal || totalEl.value || '0');
        var sum   = 0;
        splitContainer.querySelectorAll('input[data-split-amount]').forEach(function (inp) {
            var v = parseFloat(inp.value.replace(',', '.'));
            if (!isNaN(v)) sum += v;
        });
        sum = Math.round(sum * 100) / 100;
        total = Math.round(total * 100) / 100;
        sumEl.textContent = sum.toFixed(2).replace('.', ',');
        var ok = Math.abs(sum - total) <= 0.01;
        sumEl.closest('.split-sum').classList.toggle('split-sum--ok', ok);
        sumEl.closest('.split-sum').classList.toggle('split-sum--error', !ok);
        return ok;
    }

    splitContainer.addEventListener('input', updateSum);
    updateSum();
})();

/* ── Aktions-Dropdowns (.dash-actions-menu) schließen bei Klick außen ── */

document.addEventListener('click', function (e) {
    if (!e.target.closest('.dash-actions-menu')) {
        document.querySelectorAll('.dash-actions-menu[open]').forEach(function (d) {
            d.removeAttribute('open');
        });
    }
});

/* ── Toggle visibility (data-toggle-target) ── */

document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.dataset.toggleTarget) return;
    var target = document.querySelector(el.dataset.toggleTarget);
    if (!target) return;
    target.classList.toggle('hidden', !el.checked);
});

document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-toggle-target]');
    if (!btn || btn.tagName !== 'BUTTON') return;
    var target = document.querySelector(btn.dataset.toggleTarget);
    if (!target) return;
    target.classList.toggle('hidden');
});

/* ── Cover image focal point picker ── */

(function () {
    var picker = document.getElementById('focal-picker');
    if (!picker) return;
    var img   = document.getElementById('focal-img');
    var input = document.getElementById('focal-input');
    var dots  = picker.querySelectorAll('.focal-dot');

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            var pos = this.dataset.pos;
            input.value = pos;
            img.style.objectPosition = pos;
            dots.forEach(function (d) {
                d.classList.remove('is-active');
                d.setAttribute('aria-pressed', 'false');
            });
            this.classList.add('is-active');
            this.setAttribute('aria-pressed', 'true');
        });
    });
}());
