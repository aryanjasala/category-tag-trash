const { test, expect, wp } = require( './utils' );

const TRASH = '/wp-admin/tools.php?page=term-trash';

/**
 * A category with a child, meta, two posts and a menu link.
 *
 * @param {string} prefix Unique name prefix.
 * @return {Object} IDs.
 */
function seed( prefix ) {
	const id = ( output ) => Number( output );
	const cat = id( wp( 'term', 'create', 'category', `${ prefix } Recipes`, '--description=Food posts', '--porcelain' ) );
	const child = id( wp( 'term', 'create', 'category', `${ prefix } Desserts`, `--parent=${ cat }`, '--porcelain' ) );
	const news = id( wp( 'term', 'create', 'category', `${ prefix } News`, '--porcelain' ) );
	wp( 'term', 'meta', 'add', String( cat ), 'color', 'red' );
	const only = id( wp( 'post', 'create', `--post_title=${ prefix } only recipes`, '--post_status=publish', `--post_category=${ cat }`, '--porcelain' ) );
	const both = id( wp( 'post', 'create', `--post_title=${ prefix } recipes and news`, '--post_status=publish', `--post_category=${ cat },${ news }`, '--porcelain' ) );
	const menu = id( wp( 'menu', 'create', `${ prefix } Menu`, '--porcelain' ) );
	wp( 'menu', 'item', 'add-term', String( menu ), 'category', String( cat ) );
	return { cat, child, news, only, both, menu };
}

const categoriesOf = ( postId ) => wp( 'post', 'term', 'list', String( postId ), 'category', '--field=name' ).split( '\n' ).sort();

function expectFullyRestored( ids, prefix ) {
	expect( wp( 'term', 'get', 'category', String( ids.cat ), '--field=name' ) ).toBe( `${ prefix } Recipes` );
	expect( wp( 'term', 'meta', 'get', String( ids.cat ), 'color' ) ).toBe( 'red' );
	expect( categoriesOf( ids.only ) ).toEqual( [ `${ prefix } Recipes` ] );
	expect( categoriesOf( ids.both ) ).toEqual( [ `${ prefix } News`, `${ prefix } Recipes` ].sort() );
	expect( wp( 'term', 'get', 'category', String( ids.child ), '--field=parent' ) ).toBe( String( ids.cat ) );
	expect( wp( 'menu', 'item', 'list', String( ids.menu ), '--fields=object_id', '--format=csv' ).split( '\n' ).slice( 1 ) ).toEqual( [ String( ids.cat ) ] );
}

test.describe( 'Term Trash', () => {
	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
	} );

	test( 'deleting a category shows Undo, and Undo brings everything back', async ( { page } ) => {
		const ids = seed( 'Undo' );
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
		const row = page.locator( `#tag-${ ids.cat }` );
		await row.hover();
		await row.locator( '.delete-tag' ).click();

		const notice = page.locator( '.term-trash-undo' );
		await expect( notice ).toContainText( '"Undo Recipes" moved to Term Trash.' );
		await expect( row ).toHaveCount( 0 );
		expect( categoriesOf( ids.only ) ).toEqual( [ 'Uncategorized' ] );

		await notice.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( `#tag-${ ids.cat }` ) ).toBeVisible();

		expectFullyRestored( ids, 'Undo' );
	} );

	test( 'bulk delete on a term screen offers Undo for all of it', async ( { page } ) => {
		const tags = [ 'Bulk one', 'Bulk two', 'Bulk keep' ].map( ( name ) => Number( wp( 'term', 'create', 'post_tag', name, '--porcelain' ) ) );
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await page.goto( '/wp-admin/edit-tags.php?taxonomy=post_tag' );
		await page.locator( `#cb-select-${ tags[ 0 ] }` ).check();
		await page.locator( `#cb-select-${ tags[ 1 ] }` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'delete' );
		await page.locator( '#doaction' ).click();

		const notice = page.locator( '.term-trash-undo' );
		await expect( notice ).toContainText( '2 terms moved to Term Trash.' );
		await notice.getByRole( 'link', { name: 'Undo' } ).click();

		await expect( page.locator( '.tt-restored' ) ).toContainText( 'Restored Bulk one, Bulk two.' );
		await expect( page.locator( `#tag-${ tags[ 0 ] }` ) ).toBeVisible();
		await expect( page.locator( `#tag-${ tags[ 1 ] }` ) ).toBeVisible();
	} );

	test( 'the trash screen filters, searches, shows details and restores one term', async ( { page } ) => {
		const ids = seed( 'Screen' );
		wp( 'term', 'create', 'post_tag', 'Screen tag' );
		wp( 'term', 'delete', 'category', String( ids.cat ) );
		wp( 'term', 'delete', 'post_tag', wp( 'term', 'get', 'post_tag', 'screen-tag', '--by=slug', '--field=term_id' ) );

		await page.goto( TRASH );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Categories (1)' );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Tags (1)' );

		await page.locator( '.subsubsub' ).getByRole( 'link', { name: /Tags/ } ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '#the-list .tt-name' ) ).toHaveText( 'Screen tag' );

		await page.goto( TRASH );
		await page.locator( '#term-trash-search-input' ).fill( 'Recipes' );
		await page.locator( '#search-submit' ).click();
		const row = page.locator( '#the-list tr' );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.column-attached' ) ).toHaveText( '2 items · 1 child term · 1 menu link' );

		await row.locator( '.tt-details summary' ).click();
		const details = row.locator( '.tt-details' );
		await expect( details ).toContainText( 'Screen only recipes' );
		await expect( details ).toContainText( 'Screen Desserts' );
		await expect( details ).toContainText( 'Screen Menu' );

		await row.hover();
		await row.locator( '.tt-restore' ).click();
		await expect( page.locator( '.tt-restored' ) ).toContainText( 'Restored Screen Recipes.' );
		expectFullyRestored( ids, 'Screen' );
	} );

	test( 'bulk restore, delete permanently, empty trash and the retention setting', async ( { page } ) => {
		const make = ( names ) =>
			names.forEach( ( name ) => {
				const id = wp( 'term', 'create', 'post_tag', name, '--porcelain' );
				wp( 'term', 'delete', 'post_tag', id );
			} );

		make( [ 'Batch A', 'Batch B', 'Batch C' ] );
		await page.goto( TRASH );
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'restore' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.tt-restored' ) ).toContainText( 'Batch A' );
		await expect( page.locator( '#the-list' ) ).toContainText( 'Term Trash is empty.' );

		for ( const slug of [ 'batch-a', 'batch-b', 'batch-c' ] ) {
			wp( 'term', 'delete', 'post_tag', wp( 'term', 'get', 'post_tag', slug, '--by=slug', '--field=term_id' ) );
		}
		await page.goto( TRASH );
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'delete' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( '3 terms deleted for good.' );

		make( [ 'Batch D' ] );
		await page.goto( TRASH );
		await page.getByRole( 'button', { name: 'Empty trash' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( '1 term deleted for good.' );
		await expect( page.getByRole( 'button', { name: 'Empty trash' } ) ).toHaveCount( 0 );

		await page.locator( '#tt-retention' ).selectOption( '90' );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		await expect( page.locator( '.tt-intro' ) ).toContainText( 'kept here for 90 days' );
		wp( 'option', 'delete', 'term_trash_settings' );
	} );

	test( 'restores under a new slug when the old one was reused, from WP-CLI', () => {
		const id = wp( 'term', 'create', 'category', 'Reused', '--porcelain' );
		wp( 'term', 'delete', 'category', id );
		wp( 'term', 'create', 'category', 'Reused' );

		const [ itemId ] = wp( 'term-trash', 'list', '--format=ids' ).split( ' ' );
		const output = wp( 'term-trash', 'restore', itemId );
		expect( output ).toContain( `Restored term ${ id }.` );
		expect( output ).toContain( 'restored as "reused-2"' );
		expect( wp( 'term', 'get', 'category', id, '--field=slug' ) ).toBe( 'reused-2' );
	} );
} );
