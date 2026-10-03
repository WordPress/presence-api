<?php
/**
 * DB viewer: renders the wp_presence table as a minimal standalone page.
 *
 * Accessed via ?presence-db=1 on any WordPress URL. Shows live table data
 * sorted newest first with age indicators and TTL-based row expiry.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		if ( empty( $_GET['presence-db'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wp_presence_db_viewer' ) ) {
			wp_die( 'Invalid nonce.' );
		}

		global $wpdb;

		$rows = array();

		if ( wp_presence_has_table() ) {
			$room = isset( $_GET['room'] ) ? sanitize_text_field( wp_unslash( $_GET['room'] ) ) : '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT room, user_id, client_id, data, date_gmt, expires_gmt FROM {$wpdb->presence} WHERE %s = '' OR room = %s ORDER BY date_gmt DESC",
					$room,
					$room
				)
			);
		}

		$ttl    = wp_presence_get_timeout();
		$now_ms = (int) ( microtime( true ) * 1000 );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<title>wp_presence</title>
<style>
	* { margin: 0; padding: 0; box-sizing: border-box; }
	body { font: 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: var(--wp-admin-background, #fff); color: var(--wp-admin-text, #50575e); padding: 0; overflow: auto; }

	table { border-collapse: collapse; width: 100%; table-layout: fixed; }
	th { text-align: left; padding: 4px 6px; color: var(--wp-admin-muted, #646970); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--wp-admin-border, #f0f0f1); }
	td { padding: 3px 6px; border-bottom: 1px solid var(--wp-admin-border, #f0f0f1); white-space: nowrap; }
	td:nth-child(4) { white-space: normal; word-break: break-word; }
	tr:hover td { background: #f6f7f7; }
	th:nth-child(1) { width: 24%; }
	th:nth-child(2) { width: 7%; }
	th:nth-child(3) { width: 13%; }
	th:nth-child(4) { width: 38%; }
	th:nth-child(5) { width: 9%; }
	th:nth-child(6) { width: 9%; }

	tr.is-new td { background: #f0f6e8; }
	tr.is-fresh td { color: var(--wp-admin-text-dark, #1d2327); }
	tr.is-stale td { color: var(--wp-admin-muted, #646970); }

	.empty { color: var(--wp-admin-muted, #646970); padding: 12px 6px; }
</style>
</head>
<body>

<p class="empty"<?php echo ! empty( $rows ) ? ' style="display:none"' : ''; ?>><?php esc_html_e( 'No entries.', 'presence-api' ); ?></p>
		<?php if ( ! empty( $rows ) ) : ?>
<table>
<thead>
<tr><th>room</th><th>id</th><th>client</th><th>data</th><th>age</th><th>expires</th></tr>
</thead>
<tbody>
			<?php
			foreach ( $rows as $row ) :
				$ts_ms  = (int) ( strtotime( $row->date_gmt . ' +0000' ) * 1000 );
				$exp    = isset( $row->expires_gmt ) ? $row->expires_gmt : '';
				$exp_ms = ( '' !== $exp && '0000-00-00 00:00:00' !== $exp )
					? (int) ( strtotime( $exp . ' +0000' ) * 1000 )
					: $ts_ms + $ttl * 1000;
				?>
<tr data-ts="<?php echo esc_attr( $ts_ms ); ?>" data-expires="<?php echo esc_attr( $exp_ms ); ?>">
	<td><?php echo esc_html( $row->room ); ?></td>
	<td><?php echo (int) $row->user_id; ?></td>
	<td><?php echo esc_html( $row->client_id ); ?></td>
	<td>
				<?php
				$decoded = json_decode( $row->data, true );
				if ( is_array( $decoded ) ) {
					if ( ! current_user_can( 'view_presence_location', (int) $row->user_id ) ) {
						unset( $decoded['screen'], $decoded['post_id'], $decoded['object_id'] );
					}
					$pairs = array();
					foreach ( $decoded as $k => $v ) {
							$pairs[] = esc_html( $k ) . ': ' . esc_html( $v );
					}
					echo implode( ', ', $pairs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each pair is escaped individually above.
				} else {
					echo esc_html( $row->data );
				}
				?>
	</td>
	<td class="age"></td>
	<td class="expires"></td>
</tr>
	<?php endforeach; ?>
</tbody>
</table>
	<?php endif; ?>

<script>
(function(){
	var serverNow = <?php echo (int) $now_ms; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast. ?>;
	var offset = serverNow - Date.now();
	function tick(){
		var now = Date.now() + offset;
		var visible = 0;
		var rows = document.querySelectorAll('tr[data-ts]');
		rows.forEach(function(tr, i){
			var age = Math.max(0, Math.round((now - Number(tr.dataset.ts)) / 1000));
			var remaining = Math.round((Number(tr.dataset.expires) - now) / 1000);
			if (remaining <= 0) {
				tr.style.display = 'none';
				return;
			}
			tr.style.display = '';
			visible++;
			var ageCell = tr.querySelector('.age');
			if (ageCell) ageCell.textContent = age + 's';
			var expCell = tr.querySelector('.expires');
			if (expCell) expCell.textContent = remaining + 's';
			tr.className = age < 5 ? 'is-new' : age < 30 ? 'is-fresh' : 'is-stale';
		});
		var table = document.querySelector('table');
		var empty = document.querySelector('.empty');
		if (table) table.style.display = visible ? '' : 'none';
		if (empty) empty.style.display = visible ? 'none' : '';
	}
	tick();
	setInterval(tick, 1000);
	// Refreshes itself, since the tab that opened it may have moved on.
	setInterval(function(){
		fetch(location.href, { cache: 'no-store' }).then(function(response){
			return response.ok ? response.text() : Promise.reject();
		}).then(function(html){
			var next = new DOMParser().parseFromString(html, 'text/html');
			next.querySelectorAll('script').forEach(function(script){ script.remove(); });
			document.body.replaceChildren.apply(document.body, Array.from(next.body.childNodes));
			tick();
		}).catch(function(){});
	}, 5000);
})();
</script>
</body>
</html>
		<?php
		exit;
	}
);
