/**
 * Active Posts dashboard widget client.
 *
 * @package Presence_API
 */

( function ( $ ) {
	'use strict';

	if ( typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined' ) {
		return;
	}

	var i18n = window.wpPresenceActivePosts || {};

	// Sitewide data, no per-tab context — the dedupe key is fixed.
	var pingContextKey = 'wp-presence-active-posts-ping';

	var hasLocks = typeof navigator !== 'undefined' &&
		navigator.locks &&
		typeof navigator.locks.request === 'function';

	// No Locks API: ping independently, same as before.
	var isPingLeader = ! hasLocks;

	var pingChannel = typeof BroadcastChannel === 'function'
		? new BroadcastChannel( pingContextKey )
		: null;

	if ( pingChannel ) {
		pingChannel.addEventListener( 'message', function ( event ) {
			$( document ).trigger( 'heartbeat-tick', [ event.data ] );
		} );
	}

	if ( hasLocks ) {
		// All tabs queue on this lock; the winner leads until its tab closes.
		navigator.locks
			.request( pingContextKey, function () {
				isPingLeader = true;
				return new Promise( function () {} );
			} )
			.catch( function () {} );
	}

	function esc(str) {
		var el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	$(document).on('heartbeat-send', function(event, data) {
		if ( ! isPingLeader ) {
			return;
		}
		data['presence-active-posts-ping'] = true;
	});

	if ( pingChannel ) {
		$( document ).on( 'heartbeat-tick', function ( event, data ) {
			if ( ! isPingLeader || ! data || ! data[ 'presence-active-posts' ] ) {
				return;
			}
			pingChannel.postMessage( { 'presence-active-posts': data[ 'presence-active-posts' ] } );
		} );
	}

	var lastSignature = '';

	function captureFocus(container) {
		var active = document.activeElement;
		if (!active || !$.contains(container[0], active)) {
			return null;
		}
		var item = $(active).closest('[data-post-id]');
		return { postId: item.length ? item.data('post-id') : null };
	}

	function restoreFocus(container, info) {
		if (!info) {
			return;
		}
		var target = null;
		if (info.postId !== null && info.postId !== undefined) {
			target = container.find('[data-post-id="' + info.postId + '"] a').first();
		}
		if (target && target.length) {
			target.trigger('focus');
		} else {
			container.trigger('focus');
		}
	}

	function buildFullPostsHtml(posts) {
		var html = '<ul class="presence-active-posts-list" aria-label="' + esc(i18n.postsBeingEdited) + '">';
		posts.forEach(function(post) {
			var anyActive = post.editors.some(function(e) { return e.status === 'active'; });
			var statusLabel = anyActive ? '' : i18n.statusIdle;
			html += '<li class="presence-active-post-item" data-post-id="' + post.post_id + '">';
			html += '<span class="presence-editor-stack">';
			var stackMax = Math.min(post.editors.length, 4);
			post.editors.slice(0, stackMax).forEach(function(editor, idx) {
				if (editor.avatar_url) {
					html += '<img src="' + esc(editor.avatar_url) + '" width="24" height="24" style="z-index:' + (stackMax - idx) + '" alt="' + esc(editor.display_name) + '" />';
				}
			});
			html += '</span>';
			html += '<div class="presence-active-post-info">';
			if (post.editors.length === 1) {
				html += '<div><span class="presence-editor-count">' + esc(post.editors[0].display_name) + '</span></div>';
			} else {
				html += '<div><span class="presence-editor-count">' + esc(i18n.editorCount.replace('%d', post.editors.length)) + '</span></div>';
			}
			html += '<div><span class="presence-post-title"><a href="' + esc(post.edit_url) + '">' + esc(post.post_title) + '</a></span></div>';
			html += '</div>';
			html += '<span class="presence-status-text">' + esc(statusLabel) + '</span>';
			html += '</li>';
		});
		html += '</ul>';
		return html;
	}

	$(document).on('heartbeat-tick', function(event, data) {
		if (!data['presence-active-posts']) {
			return;
		}

		var container = $('#presence-active-posts-list');
		if (!container.length) {
			return;
		}

		var posts = data['presence-active-posts'];
		if (!posts.length) {
			if (lastSignature !== '') {
				var clearFocus = captureFocus(container);
				container.html('<p>' + esc(i18n.noPostsEdited) + '</p>');
				restoreFocus(container, clearFocus);
				lastSignature = '';
			}
			return;
		}

		var sig = posts.map(function(p) {
			return p.post_id + ':' + p.editors.map(function(e) { return e.user_id + '/' + e.status; }).join('+');
		}).join(',');
		if (sig !== lastSignature) {
			var focusInfo = captureFocus(container);
			container.html(buildFullPostsHtml(posts));
			restoreFocus(container, focusInfo);
			lastSignature = sig;
		}
	});
})(jQuery);
