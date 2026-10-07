/**
 * WordPress dependencies
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

// Read from the `wp-presence` script, as another plugin would, so the panel shares its poll.
const { usePresenceUsers } = window.wp.presence;

/**
 * Lists everyone else in the post's room.
 *
 * @return {Element} The panel.
 */
function EditorsPanel() {
	const { isLoading, users } = usePresenceUsers(
		window.wpPresenceEditorsPanel.room
	);

	let content = null;
	if ( ! isLoading && ! users.length ) {
		content = <p>{ __( 'No one else is editing.', 'presence-api' ) }</p>;
	} else if ( ! isLoading ) {
		content = (
			<ul style={ { margin: 0 } }>
				{ users.map( ( user ) => (
					<li
						key={ user.id }
						style={ {
							display: 'flex',
							alignItems: 'center',
							gap: '8px',
						} }
					>
						<img
							src={ user.avatarUrl }
							alt=""
							width="24"
							height="24"
							style={ { borderRadius: '9999px' } }
						/>
						{ user.displayName }
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<PluginDocumentSettingPanel
			name="presence-editors"
			title={ __( 'Editors', 'presence-api' ) }
		>
			{ content }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'presence-editors', { render: EditorsPanel } );
