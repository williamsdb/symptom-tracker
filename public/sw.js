'use strict';
// Notification-only worker: never cache private diary pages or API responses.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('push', event => {
  let message = {};
  try { message = event.data ? event.data.json() : {}; } catch (_) { /* Display a generic fallback. */ }
  event.waitUntil(self.registration.showNotification('Daywell', {
    body: message.test ? 'Your reminders are ready. This is a test notification.' : 'Time for your daily Daywell check-in.',
    icon: new URL('assets/apple-touch-icon.png', self.registration.scope).href,
    badge: new URL('assets/favicon.svg', self.registration.scope).href,
    tag: message.test ? 'daywell-test' : 'daywell-daily',
    data: { url: new URL('?page=log', self.registration.scope).href }
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = new URL('?page=log', self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async windows => {
    for (const client of windows) {
      if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
        await client.navigate(url); return client.focus();
      }
    }
    return self.clients.openWindow(url);
  }));
});
