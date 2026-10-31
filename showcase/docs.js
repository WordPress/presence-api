// Folds the sidebar on narrow screens, and marks the section in view in the page's contents.
( () => {
	const sidebar = document.querySelector( '.docs-sidebar' );
	if ( sidebar && window.matchMedia( '(max-width: 900px)' ).matches ) {
		sidebar.open = false;
	}

	const links = [ ...document.querySelectorAll( '.docs-toc a' ) ];
	const headings = links.map( ( link ) =>
		document.getElementById( link.hash.slice( 1 ) )
	);
	let frame;

	const mark = () => {
		frame = null;
		const atBottom =
			window.innerHeight + window.scrollY >=
			document.documentElement.scrollHeight - 2;
		let current = 0;
		headings.forEach( ( heading, index ) => {
			if ( atBottom || heading.getBoundingClientRect().top < 120 ) {
				current = index;
			}
		} );
		links.forEach( ( link, index ) =>
			index === current
				? link.setAttribute( 'aria-current', 'location' )
				: link.removeAttribute( 'aria-current' )
		);
	};

	if ( links.length ) {
		window.addEventListener(
			'scroll',
			() => {
				frame ??= window.requestAnimationFrame( mark );
			},
			{ passive: true }
		);
		mark();
	}
} )();
