/**
 * Live Operator — service worker (scope: the app, /live-operator/).
 *
 * Only shows the notifications the site sends (Web Push, encrypted end to
 * end) and opens the app on a click. No caching: the app needs the network
 * anyway, and an old copy of it would show an old inbox.
 */
'use strict';

self.addEventListener('install', function () {
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
	var data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch (e) {
		data = { body: event.data ? event.data.text() : '' };
	}
	var url = typeof data.url === 'string' && data.url ? data.url : self.registration.scope;
	event.waitUntil(
		self.registration.showNotification(data.title || 'Live Operator', {
			body: data.body || '',
			tag: data.tag || 'lop',
			renotify: true,
			requireInteraction: false,
			data: { url: url }
		})
	);
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var url = (event.notification.data && event.notification.data.url) || self.registration.scope;
	// Only addresses of this app: a payload can never open another site.
	if (url.indexOf(self.registration.scope) !== 0) {
		url = self.registration.scope;
	}
	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
			for (var i = 0; i < list.length; i++) {
				var c = list[i];
				if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
					c.postMessage({ type: 'lop-open', url: url });
					return c.focus();
				}
			}
			return self.clients.openWindow(url);
		})
	);
});
