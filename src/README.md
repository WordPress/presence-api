# React Hooks

## Installation

Build the hooks with:

```bash
npm install
npm run build
```

This generates `assets/js/build/index.js` and `assets/js/build/index.asset.php`.

## Enqueuing

The built JavaScript is exposed as `window.wp.presenceApi` for use in the block editor and other WordPress admin screens.

```php
<?php
add_action( 'enqueue_block_editor_assets', function() {
	$asset_file = include plugin_dir_path( __FILE__ ) . 'assets/js/build/index.asset.php';
	
	wp_enqueue_script(
		'wp-presence-hooks',
		plugins_url( 'assets/js/build/index.js', __FILE__ ),
		$asset_file['dependencies'],
		$asset_file['version'],
		true
	);
} );
```

The build system automatically tracks dependencies (wp-api-fetch, wp-data, wp-element) in the `.asset.php` file.

## usePresenceUsers

Subscribes to WordPress Heartbeat to poll for presence room occupants.

### Usage

```js
const { usePresenceUsers } = window.wp.presenceApi;

function MyComponent( { postId } ) {
	const { isPresent, isLoading, users, error } = usePresenceUsers( 
		`postType/post:${ postId }` 
	);

	if ( error ) {
		return <div>Error loading presence: { error.message }</div>;
	}

	if ( isLoading ) {
		return <div>Loading...</div>;
	}

	if ( ! isPresent ) {
		return null;
	}

	return (
		<div>
			<div 
				role="status" 
				aria-live="polite" 
				aria-atomic="true"
				className="screen-reader-text"
			>
				{ users.length } { users.length === 1 ? 'person' : 'people' } editing
			</div>
			{ users.map( ( user ) => (
				<div key={ user.id }>
					<img 
						src={ user.avatarUrl } 
						alt=""
						width="32"
						height="32"
					/>
					<span>{ user.displayName }</span>
				</div>
			) ) }
		</div>
	);
}
```

### Parameters

**room** `string|null|undefined`

Presence room id (e.g. `postType/post:123`). When `null` or `undefined`, no requests run and the hook reports empty presence.

**options** `Object` (optional)

- `includeSelf` `boolean` - When false (default), the current user is excluded from `users`.
- `fields` `string` - Optional `_fields` parameter to limit response fields. Defaults to `user_id,display_name,avatar_url`.

### Return Value

Returns an object with:

- `isPresent` `boolean` - Whether any users are present in the room.
- `isLoading` `boolean` - True until the first fetch completes. Use this to avoid flickering empty states.
- `users` `Array<Object>` - Array of user objects, each containing:
  - `id` `number` - The user ID.
  - `displayName` `string` - The user's display name.
  - `avatarUrl` `string` - The user's avatar URL.
- `error` `Error|null` - Error object if the fetch failed, null otherwise.

### Accessibility

When displaying presence information:

- **Screen reader announcements**: Use `role="status"` with `aria-live="polite"` to announce presence changes without interrupting the user.
- **Avatar images**: Use `alt=""` for avatar images (decorative, name is in adjacent text).
- **Dynamic updates**: Use `aria-atomic="true"` so the entire presence count is re-announced on change, not just the diff.
- **Animations**: Respect `prefers-reduced-motion` when animating user join/leave transitions.

Example live region pattern:

```js
<div 
	role="status" 
	aria-live="polite" 
	aria-atomic="true"
	className="screen-reader-text"
>
	{ isPresent && `${ users.length } ${ users.length === 1 ? 'person' : 'people' } editing this ${ entityType }` }
	{ ! isPresent && 'You are the only person editing' }
</div>
```

### Notes

- The hook automatically subscribes to WordPress Heartbeat's `heartbeat-tick` event to avoid duplicating network traffic.
- Requires `wp.heartbeat` to be available in the global scope.
- Deduplicates users by ID.
- Prevents race conditions: if a heartbeat tick fires while a fetch is in progress, the second request is skipped.
- In development mode, logs warnings and errors to the console for debugging.
