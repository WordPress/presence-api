<?php
/**
 * DB viewer: renders the wp_presence table as a minimal standalone page.
 *
 * Accessed via ?presence-db=1 on any WordPress URL. Shows live table data
 * sorted newest first with age indicators and TTL-based row expiry.
 *
 * @package Presence_API
 */

add_action( 'init', function () {
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

	// No user input in these queries; table name comes from $wpdb->presence (controlled).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT room, user_id, data, date_gmt FROM {$wpdb->presence} ORDER BY date_gmt DESC"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" );

	$ttl    = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );
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
	body.is-embedded { overflow: hidden; }

	table { border-collapse: collapse; width: 100%; table-layout: fixed; }
	th { text-align: left; padding: 4px 6px; color: var(--wp-admin-muted, #a7aaad); font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--wp-admin-border, #f0f0f1); }
	td { padding: 3px 6px; border-bottom: 1px solid var(--wp-admin-border, #f0f0f1); white-space: nowrap; }
	td:nth-child(3) { white-space: normal; word-break: break-word; }
	tr:hover td { background: #f6f7f7; }
	th:nth-child(1) { width: 30%; }
	th:nth-child(2) { width: 10%; }
	th:nth-child(3) { width: 48%; }
	th:nth-child(4) { width: 12%; }

	tr.is-new td { background: #f0f6e8; }
	tr.is-fresh td { color: var(--wp-admin-text-dark, #1d2327); }
	tr.is-stale td { color: var(--wp-admin-muted, #a7aaad); }

	.empty { color: var(--wp-admin-muted, #a7aaad); padding: 12px 6px; }
</style>
</head>
<body<?php echo $is_embedded ? ' class="is-embedded"' : ''; ?>>

<p class="empty"<?php echo ! empty( $rows ) ? ' style="display:none"' : ''; ?>><?php esc_html_e( 'No entries.', 'presence-api' ); ?></p>
<?php if ( ! empty( $rows ) ) : ?>
<table>
<thead>
<tr><th>room</th><th>id</th><th>data</th><th>age</th></tr>
</thead>
<tbody>
<?php
$max_visible = 10;
$is_embedded = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'];
$row_limit   = $is_embedded ? $max_visible : count( $rows );
foreach ( $rows as $i => $row ) :
	if ( $i >= $row_limit ) {
		break;
	}
	$ts_ms = (int) ( strtotime( $row->date_gmt . ' +0000' ) * 1000 );
?>
<tr data-ts="<?php echo $ts_ms; ?>">
	<td><?php echo esc_html( $row->room ); ?></td>
	<td><?php echo (int) $row->user_id; ?></td>
	<td><?php
		$decoded = json_decode( $row->data, true );
		if ( is_array( $decoded ) ) {
			$pairs = array();
			foreach ( $decoded as $k => $v ) {
				$pairs[] = esc_html( $k ) . ': ' . esc_html( $v );
			}
			echo implode( ', ', $pairs );
		} else {
			echo esc_html( $row->data );
		}
	?></td>
	<td class="age"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ( $is_embedded ) : ?>
<p class="overflow-link" style="padding:6px;font-size:11px;color:#a7aaad;text-align:center;display:none;">
	<a href="<?php echo esc_url( wp_nonce_url( home_url( '/?presence-db=1' ), 'wp_presence_db_viewer' ) ); ?>" target="_blank" rel="noopener noreferrer" style="color:var(--wp-admin-muted, #a7aaad);text-decoration:none;">
		<span class="overflow-count"></span> &#8599;
	</a>
</p>
<?php endif; ?>
<?php endif; ?>

<script>
(function(){
	var serverNow = <?php echo $now_ms; ?>;
	var offset = serverNow - Date.now();
	var TTL = <?php echo (int) $ttl; ?>;
	var allTimestamps = <?php
		$all_ts = array();
		foreach ( $rows as $row ) {
			$all_ts[] = (int) ( strtotime( $row->date_gmt . ' +0000' ) * 1000 );
		}
		echo wp_json_encode( $all_ts );
	?>;
	function tick(){
		var now = Date.now() + offset;
		var visible = 0;
		var rows = document.querySelectorAll('tr[data-ts]');
		rows.forEach(function(tr, i){
			var age = Math.max(0, Math.round((now - Number(tr.dataset.ts)) / 1000));
			if (age >= TTL) {
				tr.style.display = 'none';
				return;
			}
			tr.style.display = '';
			visible++;
			var cell = tr.querySelector('.age');
			cell.textContent = age + 's';
			tr.className = age < 5 ? 'is-new' : age < 30 ? 'is-fresh' : 'is-stale';
		});
		var table = document.querySelector('table');
		var empty = document.querySelector('.empty');
		var overflowEl = document.querySelector('.overflow-link');
		var overflowCount = document.querySelector('.overflow-count');
		if (table) table.style.display = visible ? '' : 'none';
		if (empty) empty.style.display = visible ? 'none' : '';
		if (overflowEl && overflowCount) {
			var maxVisible = <?php echo (int) $max_visible; ?>;
			var totalAlive = allTimestamps.filter(function(ts) {
				return Math.round((now - ts) / 1000) < TTL;
			}).length;
			var extra = Math.max(0, totalAlive - maxVisible);
			overflowEl.style.display = extra > 0 ? '' : 'none';
			overflowCount.textContent = '+' + extra + ' more rows';
		}
	}
	function resize() {
		if (window.parent !== window) {
			window.parent.postMessage({ presenceDbHeight: document.body.scrollHeight }, window.location.origin);
		}
	}
	tick();
	resize();
	setInterval(function() { tick(); resize(); }, 1000);
})();
</script>
</body>
</html>
<?php
	exit;
} );
