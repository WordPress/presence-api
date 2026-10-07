/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';

// Read from the `wp-presence` script, as another plugin would, so every pattern on the page shares its poll.
const { usePresenceUsers } = window.wp.presence;

/**
 * Says on a synced pattern that someone else has it open in its own editor, so its content may change.
 *
 * @param {Object} props
 * @param {number} props.id The pattern's post ID.
 * @return {Element|null} The notice, or nothing while no one else is editing it.
 */
function PatternEditors( { id } ) {
	const { users } = usePresenceUsers( `postType/wp_block:${ id }` );

	if ( ! users.length ) {
		return null;
	}

	const message =
		users.length === 1
			? sprintf(
					/* translators: %s: Display name. */
					__( '%s is editing this pattern.', 'presence-api' ),
					users[ 0 ].displayName
				)
			: sprintf(
					/* translators: 1: Display name, 2: Number of other people. */
					_n(
						'%1$s and %2$d other person are editing this pattern.',
						'%1$s and %2$d other people are editing this pattern.',
						users.length - 1,
						'presence-api'
					),
					users[ 0 ].displayName,
					users.length - 1
				);

	return (
		<Notice status="warning" isDismissible={ false }>
			{ message }
		</Notice>
	);
}

const withPatternEditors = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => (
		<>
			{ props.name === 'core/block' && props.attributes.ref && (
				<PatternEditors id={ props.attributes.ref } />
			) }
			<BlockEdit { ...props } />
		</>
	),
	'withPatternEditors'
);

addFilter(
	'editor.BlockEdit',
	'presence-api/synced-pattern-editors',
	withPatternEditors
);
