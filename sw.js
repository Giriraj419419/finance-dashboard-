/**
 * Finance Dashboard — service worker for Web Push notifications.
 *
 * MUST be served from the site root (/sw.js) so its scope covers every page.
 * Contains no secrets — the VAPID public key is fetched from the page and
 * used only in the browser to subscribe.
 */

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    let data = { title: 'Finance Dashboard', body: 'You have a new reminder.', url: '/dashboard.php', tag: 'reminder' };
    try {
        if (event.data) {
            var parsed = event.data.json();
            data = Object.assign(data, parsed);
        }
    } catch (e) {
        // fall back to text payload
        try { data.body = event.data.text(); } catch (_) {}
    }
    var options = {
        body: data.body,
        tag: data.tag || 'finance-reminder',
        renotify: true,
        requireInteraction: false,
        data: { url: data.url || '/dashboard.php' }
    };
    event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || '/dashboard.php';
    // Resolve against this SW's origin — never open cross-origin URLs.
    var url;
    try { url = new URL(target, self.location.origin); }
    catch (e) { url = new URL('/dashboard.php', self.location.origin); }
    if (url.origin !== self.location.origin) {
        url = new URL('/dashboard.php', self.location.origin);
    }
    event.waitUntil((async function () {
        var all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (var i = 0; i < all.length; i++) {
            var c = all[i];
            if (c.url.indexOf(self.location.origin) === 0) {
                try { await c.navigate(url.href); } catch (_) {}
                try { await c.focus(); return; } catch (_) {}
            }
        }
        await self.clients.openWindow(url.href);
    })());
});
