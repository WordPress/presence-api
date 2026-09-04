/**
 * Who's Online dashboard widget client.
 *
 * @package Presence_API
 */

(function($) {
	if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
		return;
	}

	var config = window.wpPresenceWhosOnline || {};
	var screenLabels = config.screenLabels || {};
	var screenUrls = config.screenUrls || {};
	var i18n = config.i18n || {};
	var idleThreshold = config.idleThreshold;
	var overflowThreshold = config.overflowThreshold;
	var usersUrl = config.usersUrl || '';
	var avatarMax = config.avatarMax;

	function esc(str) {
		var el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	function friendlyScreen(slug) {
		if (screenLabels[slug]) {
			return screenLabels[slug];
		}
		return slug.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
	}

	function relativeTime(dateGmt) {
		var seconds = Math.floor(Date.now() / 1000 - new Date(dateGmt + 'Z').getTime() / 1000);
		if (seconds < idleThreshold) {
			return '';
		}
		if (seconds < 60) {
			return i18n.secondsAgo.replace('%d', seconds);
		}
		var minutes = Math.floor(seconds / 60);
		if (minutes < 60) {
			return i18n.minutesAgo.replace('%d', minutes);
		}
		var hours = Math.floor(minutes / 60);
		return (hours > 1 ? i18n.hoursAgo : i18n.hourAgo).replace('%d', hours);
	}

	var isExpanded = false;
	var lastSignature = '';
	var lastEntries = [];
	var lastHash = '';

	function captureFocus(container) {
		var active = document.activeElement;
		if (!active || !$.contains(container[0], active)) {
			return null;
		}
		var $active = $(active);
		var item = $active.closest('[data-user-id]');
		if (item.length) {
			return { type: 'user', id: item.data('user-id') };
		}
		var action = $active.data('action');
		if (action) {
			return { type: 'action', action: action };
		}
		return { type: 'none' };
	}

	function restoreFocus(container, info) {
		if (!info) {
			return;
		}
		var target = null;
		if (info.type === 'user') {
			target = container.find('[data-user-id="' + info.id + '"] a, [data-user-id="' + info.id + '"] button').first();
		} else if (info.type === 'action') {
			target = container.find('[data-action="' + info.action + '"]');
		}
		if (target && target.length) {
			target.trigger('focus');
		} else {
			container.trigger('focus');
		}
	}

	function buildRowHtml(entry) {
		var html = '';
		if (entry.avatar_url) {
			html += '<img src="' + esc(entry.avatar_url) + '" width="24" height="24" alt="' + esc(entry.display_name) + '" />';
		}
		var timeStr = entry.date_gmt ? relativeTime(entry.date_gmt) : '';
		var dotTitle = timeStr || i18n.onlineNow;
		var elapsed = entry.date_gmt ? Math.floor(Date.now() / 1000 - new Date(entry.date_gmt + 'Z').getTime() / 1000) : 0;
		var idleClass = elapsed >= idleThreshold ? ' is-idle' : '';
		html += '<div class="presence-user-info">';
		html += '<span class="presence-name">' + esc(entry.display_name) + '</span>';
		if (entry.screen) {
			var screenText = entry.screen_label || friendlyScreen(entry.screen);
			var parts = screenText.split(' ');
			var formatted = parts.length > 1 ? '<em>' + esc(parts[0]) + '</em> ' + esc(parts.slice(1).join(' ')) : esc(screenText);
			if (screenUrls[entry.screen]) {
				html += '<span class="presence-screen"><a href="' + esc(screenUrls[entry.screen]) + '">' + formatted + '</a></span>';
			} else {
				html += '<span class="presence-screen">' + formatted + '</span>';
			}
		}
		html += '</div>';
		html += '<span class="presence-online-dot' + idleClass + '" role="img" aria-label="' + esc(dotTitle) + '" title="' + esc(dotTitle) + '"></span>';
		return html;
	}

	function buildFullHtml(visible, overflow, overflowTotal) {
		var html = '<ul class="presence-user-list" aria-label="' + esc(i18n.usersOnline) + '">';
		visible.forEach(function(entry) {
			html += '<li class="presence-user-item" data-user-id="' + entry.user_id + '">' + buildRowHtml(entry) + '</li>';
		});
		html += '</ul>';
		if (overflow.length) {
			if (overflowTotal > overflowThreshold) {
				// Summary mode: avatar stack + count linking to Users page.
				html += '<a href="' + esc(usersUrl) + '" class="presence-overflow-toggle">';
				html += window.wpPresenceBuildAvatarStack(overflow, avatarMax);
				html += '<span class="presence-overflow-text">' + esc(i18n.moreCountLink.replace('%d', overflowTotal)) + '</span></a>';
			} else {
				// Expandable list mode.
				html += '<button type="button" class="presence-overflow-toggle" data-action="expand" aria-expanded="' + (isExpanded ? 'true' : 'false') + '" aria-controls="presence-overflow-list"';
				if (isExpanded) html += ' style="display:none"';
				html += '>';
				html += window.wpPresenceBuildAvatarStack(overflow, avatarMax);
				html += '<span class="presence-overflow-text">' + esc(i18n.moreCount.replace('%d', overflow.length)) + '</span></button>';
				html += '<ul id="presence-overflow-list" class="presence-overflow-expanded" aria-label="' + esc(i18n.additionalUsers) + '"';
				if (!isExpanded) html += ' style="display:none"';
				html += '>';
				overflow.forEach(function(entry) {
					html += '<li class="presence-user-item" data-user-id="' + entry.user_id + '">' + buildRowHtml(entry) + '</li>';
				});
				html += '</ul>';
				html += '<button type="button" class="presence-overflow-toggle" data-action="collapse" aria-controls="presence-overflow-list"';
				if (!isExpanded) html += ' style="display:none"';
				html += '>' + esc(i18n.showLess) + '</button>';
			}
		}
		return html;
	}

	$(document).on('heartbeat-send', function(event, data) {
		if (lastHash) {
			data['presence-online-hash'] = lastHash;
		}
	});

	// Update the widget when heartbeat response comes back.
	$(document).on('heartbeat-tick', function(event, data) {
		if (data['presence-online-unchanged']) {
			// Only freshness can have moved, so feed the idle sweep and leave
			// the DOM alone.
			var seen = data['presence-online-unchanged'];
			for (var i = 0; i < lastEntries.length; i++) {
				if (seen[lastEntries[i].user_id]) {
					lastEntries[i].date_gmt = seen[lastEntries[i].user_id];
				}
			}
			return;
		}

		if (!data['presence-online']) {
			return;
		}

		lastHash = data['presence-online-hash'] || '';

		var container = $('#presence-whos-online-list');
		if (!container.length) {
			return;
		}

		var entries = data['presence-online'];
		lastEntries = entries;

		if (!entries.length) {
			if (lastSignature !== '') {
				var clearFocus = captureFocus(container);
				container.html('<p>' + esc(i18n.noUsersOnline) + '</p>');
				restoreFocus(container, clearFocus);
				lastSignature = '';
			}
			return;
		}

		// Sort by most recently active first.
		entries.sort(function(a, b) {
			return (b.date_gmt || '').localeCompare(a.date_gmt || '');
		});

		var maxRows = config.maxRows;
		var visible = entries.slice(0, maxRows);
		var overflow = entries.slice(maxRows);

		// The payload is capped, so its length understates a large room. Below
		// the threshold the cap cannot bite and the array holds all of them.
		var total = typeof data['presence-online-total'] === 'number' ? data['presence-online-total'] : entries.length;
		var overflowTotal = total - maxRows;

		// Build a signature of user IDs + screens to detect real changes. The
		// total leads it because the overflow count is now derived from the
		// total, which moves while the capped entries stay identical.
		var sig = total + '|' + entries.map(function(e) { return e.user_id + ':' + (e.screen || ''); }).join(',');

		if (sig !== lastSignature) {
			// Content changed — swap HTML instantly.
			var focusInfo = captureFocus(container);
			container.html(buildFullHtml(visible, overflow, overflowTotal));
			restoreFocus(container, focusInfo);
			lastSignature = sig;
		} else {
			// Same users, same screens — update only dot tooltips.
			container.find('.presence-user-item').each(function() {
				var uid = $(this).data('user-id');
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].user_id === uid) {
						var timeStr = entries[i].date_gmt ? relativeTime(entries[i].date_gmt) : '';
						var dotTitle = timeStr || i18n.onlineNow;
						$(this).find('.presence-online-dot').attr('title', dotTitle).attr('aria-label', dotTitle);
						break;
					}
				}
			});
		}
	});

	// Re-evaluate idle dots between heartbeat ticks.
	setInterval(function() {
		if (!lastEntries.length) return;
		$('#presence-whos-online-list .presence-user-item').each(function() {
			var uid = $(this).data('user-id');
			for (var i = 0; i < lastEntries.length; i++) {
				if (lastEntries[i].user_id === uid && lastEntries[i].date_gmt) {
					var elapsed = Math.floor(Date.now() / 1000 - new Date(lastEntries[i].date_gmt + 'Z').getTime() / 1000);
					var dot = $(this).find('.presence-online-dot');
					dot.toggleClass('is-idle', elapsed >= idleThreshold);
					var timeStr = relativeTime(lastEntries[i].date_gmt);
					var dotTitle = timeStr || i18n.onlineNow;
					dot.attr('title', dotTitle).attr('aria-label', dotTitle);
					break;
				}
			}
		});
	}, 5000);

	// Toggle expand/collapse.
	$('#presence-whos-online-list').on('click', '.presence-overflow-toggle', function() {
		isExpanded = $(this).data('action') === 'expand';
		var wrap = $('#presence-whos-online-list');
		wrap.find('[data-action="expand"]').toggle(!isExpanded).attr('aria-expanded', String(!isExpanded));
		wrap.find('#presence-overflow-list').toggle(isExpanded);
		wrap.find('[data-action="collapse"]').toggle(isExpanded).attr('aria-expanded', String(isExpanded));
	});
})(jQuery);
