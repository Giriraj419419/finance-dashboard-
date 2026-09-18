/**
 * Web Push subscription controller. Loaded ONLY on pages that render the
 * push-controls block (profile.php / settings.php). Vanilla JS, no libraries.
 *
 * The page injects two data attributes:
 *   window.__push = {
 *       vapidPublicKey: '<base64url>',
 *       csrfToken:      '<hex>',
 *   };
 */
(function () {
    'use strict';
    var cfg = window.__push || {};
    var $status = document.querySelector('[data-push-status]');
    var $enable = document.querySelector('[data-push-enable]');
    var $disable = document.querySelector('[data-push-disable]');
    var $test    = document.querySelector('[data-push-test]');

    if (!$status || !$enable) return;

    function setStatus(text, cls) {
        $status.textContent = text;
        $status.className = 'push-status ' + (cls || '');
    }

    function b64uToUint8(base64u) {
        var s = (base64u + '===').slice(0, base64u.length + (4 - base64u.length % 4) % 4);
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(s);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        setStatus('This browser does not support push notifications.', 'is-unsupported');
        $enable.disabled = true;
        return;
    }
    if (!cfg.vapidPublicKey) {
        setStatus('Server VAPID public key not configured yet.', 'is-warning');
        $enable.disabled = true;
        return;
    }

    async function currentSubscription() {
        var reg = await navigator.serviceWorker.getRegistration();
        if (!reg) return null;
        return await reg.pushManager.getSubscription();
    }

    async function refreshUi() {
        function hide(el, yes) { if (!el) return; el.classList.toggle('is-hidden', !!yes); }
        if (Notification.permission === 'denied') {
            setStatus('Notifications are blocked in this browser. Enable them in browser settings.', 'is-blocked');
            hide($enable, true); hide($disable, true); hide($test, true);
            return;
        }
        var sub = await currentSubscription();
        if (sub) {
            setStatus('Push notifications are enabled on this browser.', 'is-enabled');
            hide($enable, true); hide($disable, false); hide($test, false);
        } else {
            setStatus('Push notifications are not enabled on this browser.', 'is-disabled');
            hide($enable, false); hide($disable, true); hide($test, true);
        }
    }

    async function register() {
        try {
            $enable.disabled = true;
            var perm = await Notification.requestPermission();
            if (perm !== 'granted') {
                setStatus('Permission was not granted.', 'is-warning');
                $enable.disabled = false;
                return;
            }
            var reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            // ensure registration is active
            await navigator.serviceWorker.ready;
            var sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: b64uToUint8(cfg.vapidPublicKey)
            });
            var payload = sub.toJSON();
            var res = await fetch('/push-subscribe.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    _csrf: cfg.csrfToken,
                    endpoint: payload.endpoint,
                    keys: payload.keys
                })
            });
            // csrf_check_or_die reads from $_POST, but fetch with JSON body doesn't populate $_POST.
            // Workaround: also send the token as a header the server can accept, OR use form-encoded.
            // Simpler: retry with form-encoded if JSON was rejected as 400.
            if (!res.ok && res.status === 400) {
                var body = new URLSearchParams();
                body.set('_csrf', cfg.csrfToken);
                body.set('endpoint', payload.endpoint);
                body.set('p256dh', payload.keys.p256dh);
                body.set('auth', payload.keys.auth);
                res = await fetch('/push-subscribe.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
            }
            if (!res.ok) throw new Error('server rejected subscription (' + res.status + ')');
            await refreshUi();
        } catch (e) {
            setStatus('Could not enable push: ' + (e && e.message ? e.message : e), 'is-error');
            $enable.disabled = false;
        }
    }

    async function unregister() {
        try {
            var sub = await currentSubscription();
            if (sub) {
                await fetch('/push-unsubscribe.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ _csrf: cfg.csrfToken, endpoint: sub.endpoint }).toString()
                });
                await sub.unsubscribe();
            }
            await refreshUi();
        } catch (e) {
            setStatus('Could not disable push: ' + e.message, 'is-error');
        }
    }

    async function test() {
        try {
            $test.disabled = true;
            var res = await fetch('/push-test.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ _csrf: cfg.csrfToken }).toString()
            });
            var body = await res.json();
            if (body.ok) setStatus('Test push sent — you should see a notification shortly.', 'is-enabled');
            else setStatus('Test push failed: ' + (body.error || 'unknown'), 'is-error');
        } catch (e) {
            setStatus('Test failed: ' + e.message, 'is-error');
        } finally {
            $test.disabled = false;
        }
    }

    $enable.addEventListener('click', register);
    if ($disable) $disable.addEventListener('click', unregister);
    if ($test)    $test.addEventListener('click', test);

    refreshUi();
})();
