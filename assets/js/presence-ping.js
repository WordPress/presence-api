(function ($) {
	if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
		return;
	}

	const config = window.wpPresenceConfig || {};
	const entries = Array.isArray(config.entries) ? config.entries : [];
	const frontContext = config.frontContext || null;
	const editorPostId = parseInt(config.editorPostId, 10) || 0;
	const restUrl = config.restUrl || '';
	const nonce = config.nonce || '';

	// Guards against duplicate leave() invocations.
	let hasLeft = false;

	// Tabs on the same screen and post send an identical ping. Key by both
	// so a different post or screen never gets coalesced with this one.
	const pingContextKey = 'wp-presence-ping:' + JSON.stringify({
		screen: window.pagenow || 'front',
		editorPostId: editorPostId,
		frontTitle: (frontContext && frontContext.title) || '',
		frontPostId: (frontContext && frontContext.post_id) || 0,
	});

	const hasLocks = typeof navigator !== 'undefined' &&
		navigator.locks &&
		typeof navigator.locks.request === 'function';

	// No Locks API: ping independently, same as before.
	let isPingLeader = !hasLocks;

	const pingChannel = typeof BroadcastChannel === 'function'
		? new BroadcastChannel(pingContextKey)
		: null;

	// Response keys other presence-api features read off heartbeat-tick.
	// Followers relay these from the leader instead of going stale.
	const RELAYED_TICK_KEYS = [
		'presence-online',
		'presence-online-total',
		'presence-online-hash',
		'presence-online-unchanged',
		'presence-heartbeat-users',
		'presence-heartbeat-entries',
		'presence-heartbeat-query-ms',
		'presence-heartbeat-ttl',
		'presence-heartbeat-room-list',
	];

	if (pingChannel) {
		pingChannel.addEventListener('message', function (event) {
			$(document).trigger('heartbeat-tick', [event.data]);
		});
	}

	if (hasLocks) {
		// All tabs queue on this lock; whichever gets it becomes leader.
		// Closing or crashing the leader's tab releases it automatically.
		navigator.locks
			.request(pingContextKey, function () {
				isPingLeader = true;
				return new Promise(function () {});
			})
			.catch(function () {});
	}

	// Defer registration to document ready to ensure it runs after WP Core's post.js handler.
	$(function () {
		$(document).on('heartbeat-send', function (event, data) {
			// Skip while the document is hidden (background tab, minimized
			// window, app switched away) so the existing entries expire via
			// the default TTL. One early-return suppresses both presence-ping
			// and presence-editor-ping, since the consolidated handler emits
			// both.
			if (document.visibilityState === 'hidden') {
				delete data['wp-refresh-post-lock'];
				return;
			}

			hasLeft = false;

			if (!isPingLeader) {
				return;
			}

			const ping = { screen: window.pagenow || 'front' };
			if (frontContext) {
				if (frontContext.title) {
					ping.title = frontContext.title;
				}
				if (frontContext.post_id) {
					ping.post_id = frontContext.post_id;
				}
			}
			data['presence-ping'] = ping;

			if (editorPostId) {
				data['presence-editor-ping'] = { post_id: editorPostId };
			}
		});

		if (pingChannel) {
			$(document).on('heartbeat-tick', function (event, data) {
				if (!isPingLeader || !data) {
					return;
				}

				const relayed = {};
				let hasRelayedData = false;

				RELAYED_TICK_KEYS.forEach(function (key) {
					if (Object.prototype.hasOwnProperty.call(data, key)) {
						relayed[key] = data[key];
						hasRelayedData = true;
					}
				});

				if (hasRelayedData) {
					pingChannel.postMessage(relayed);
				}
			});
		}
	});

	function leave() {
		if (hasLeft || !restUrl || !entries.length) {
			return;
		}
		hasLeft = true;

		// keepalive lets the DELETE outlive the unload; sendBeacon is POST-only.
		if (typeof window.fetch !== 'function') {
			return;
		}

		entries.forEach(function (entry) {
			if (!entry || !entry.room || !entry.client_id) {
				return;
			}
			const url = new URL(restUrl);
			url.searchParams.set('room', entry.room);
			url.searchParams.set('client_id', entry.client_id);
			try {
				window.fetch(url, {
					method: 'DELETE',
					credentials: 'same-origin',
					keepalive: true,
					headers: { 'X-WP-Nonce': nonce }
				});
			} catch {
				// Best-effort: TTL cleanup will catch entries we couldn't remove.
			}
		});
	}

	// Re-establish presence on every page load so in-admin navigation doesn't
	// leave a gap between the unload DELETE and the heartbeat's first tick.
	function tickNow() {
		if (typeof wp?.heartbeat?.connectNow === 'function') {
			wp.heartbeat.connectNow();
		}
	}
	$(tickNow);
	// bfcache restore: DOMContentLoaded won't fire.
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			tickNow();
		}
	});

	// When the tab becomes visible again, re-establish presence so the user
	// does not sit out the next heartbeat interval.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') {
			tickNow();
		}
	});

	window.addEventListener('pagehide', function () {
		leave();
	});
})(jQuery);
