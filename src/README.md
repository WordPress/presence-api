# React Hooks

## Installation

Build the hooks with:

```bash
npm install
npm run build
```

This generates `assets/js/build/index.js` and `assets/js/build/index.asset.php`.

## Enqueuing

```php
<?php
add_action( 'enqueue_block_editor_assets', function() {
	$asset_file = include plugin_dir_path( __FILE__ ) . 'assets/js/build/index.asset.php';
	
	wp_enqueue_script(
		'wp-presence-hooks',
		plugins_url( 'assets/js/build/index.js', __FILE__ ),
		$asset_file['dependencies'],
		$asset_file['version']
	);
} );
```

The build system automatically tracks dependencies (wp-api-fetch, wp-data, wp-element) in the `.asset.php` file.

## usePresenceUsers

Subscribes to WordPress Heartbeat to poll for presence room occupants.

### Usage

```js
import { usePresenceUsers } from '@wordpress/presence-api';

function MyComponent( { postId } ) {
	const { isPresent, users } = usePresenceUsers( `postType/post:${ postId }` );

	if ( ! isPresent ) {
		return null;
	}

	return (
		<div>
			{ users.map( ( user ) => (
				<div key={ user.userId }>
					<img src={ user.avatarUrl } alt="" />
					<span>{ user.displayName }</span>
				</div>
			) ) }
		</div>
	);
}
```

### Parameters

**room** `string|null|undefined`

Presence room id (e.g. `postType/chart:123`). When `null` or `undefined`, no requests run and the hook reports empty presence.

**options** `Object` (optional)

- `includeSelf` `boolean` - When false (default), the current user is excluded from `users`.
- `fields` `string` - Optional `_fields` parameter to limit response fields. Defaults to `user_id,display_name,avatar_url`.

### Return Value

Returns an object with:

- `isPresent` `boolean` - Whether any users are present in the room.
- `users` `Array<Object>` - Array of user objects, each containing:
  - `userId` `number` - The user ID.
  - `displayName` `string` - The user's display name.
  - `avatarUrl` `string` - The user's avatar URL.

### Notes

- The hook automatically subscribes to WordPress Heartbeat's `heartbeat-tick` event to avoid duplicating network traffic.
- Requires `wp.heartbeat` to be available in the global scope.
- Deduplicates users by ID.
