// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const path = require( 'path' );
const { test, expect, wp } = require( './utils' );

const shot = ( page, number ) => page.screenshot( { path: path.join( __dirname, '..', '..', '.wordpress-org', `screenshot-${ number }.png` ) } );

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 760 } } );
	let recipes;

	test.beforeAll( () => {
		recipes = wp( 'term', 'create', 'category', 'Recipes', '--description=Everything we cook', '--porcelain' );
		wp( 'term', 'create', 'category', 'Desserts', `--parent=${ recipes }` );
		const travel = wp( 'term', 'create', 'category', 'Travel', '--porcelain' );
		wp( 'term', 'create', 'category', 'News' );
		for ( const title of [ 'Sourdough basics', 'Weeknight dal', 'Lemon tart' ] ) {
			wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', `--post_category=${ recipes }` );
		}
		wp( 'post', 'create', '--post_title=Three days in Hampi', '--post_status=publish', `--post_category=${ travel }` );
		const menu = wp( 'menu', 'create', 'Main menu', '--porcelain' );
		wp( 'menu', 'item', 'add-term', menu, 'category', recipes );
	} );

	test.afterAll( () => {
		wp( 'site', 'empty', '--yes' );
	} );

	test( '1: Undo right after deleting a category', async ( { page } ) => {
		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await page.goto( '/wp-admin/edit-tags.php?taxonomy=category' );
		const row = page.locator( `#tag-${ recipes }` );
		await row.hover();
		await row.locator( '.delete-tag' ).click();
		await expect( page.locator( '.term-trash-undo' ) ).toBeVisible();
		await expect( row ).toHaveCount( 0 );
		await page.mouse.move( 0, 0 );
		// Core moves focus to the next row after a delete; drop it so no outline shows.
		await page.evaluate( () => document.activeElement.blur() );
		await shot( page, 1 );
	} );

	test( '2: Tools > Term Trash', async ( { page } ) => {
		wp( 'term', 'delete', 'category', wp( 'term', 'get', 'category', 'travel', '--by=slug', '--field=term_id' ) );
		const tag = wp( 'term', 'create', 'post_tag', 'summer', '--porcelain' );
		wp( 'term', 'delete', 'post_tag', tag );
		await page.goto( '/wp-admin/tools.php?page=term-trash' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 3 );
		await shot( page, 2 );
	} );

	test( '3: what a restore brings back', async ( { page } ) => {
		await page.goto( '/wp-admin/tools.php?page=term-trash' );
		const row = page.locator( '#the-list tr', { hasText: 'Recipes' } );
		await row.locator( '.tt-details summary' ).click();
		await row.hover();
		await shot( page, 3 );
	} );
} );
