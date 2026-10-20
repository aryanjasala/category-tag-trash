<?php
/**
 * Deletes everything in Category & Tag Trash, its settings and its schedules, on every site.
 *
 * @package CategoryTagTrash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Category & Tag Trash data from the current site.
 */
function category_tag_trash_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'category_tag_trash_purge' );
	wp_clear_scheduled_hook( 'category_tag_trash_empty' );
	delete_option( 'category_tag_trash_settings' );

	do {
		$category_tag_trash_items = get_posts(
			array(
				'post_type'   => 'category_tag_trash',
				'post_status' => 'any',
				'numberposts' => 100,
				'fields'      => 'ids',
			)
		);

		foreach ( $category_tag_trash_items as $category_tag_trash_item ) {
			wp_clear_scheduled_hook( 'category_tag_trash_restore_batch', array( $category_tag_trash_item ) );
			wp_delete_post( $category_tag_trash_item, true );
		}
	} while ( $category_tag_trash_items );

	// Locks left behind by an interrupted background restore.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'category_tag_trash_lock_' ) . '%' ) );
}

if ( is_multisite() ) {
	$category_tag_trash_offset = 0;
	do {
		$category_tag_trash_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $category_tag_trash_offset,
			)
		);

		foreach ( $category_tag_trash_sites as $category_tag_trash_site ) {
			switch_to_blog( $category_tag_trash_site );
			category_tag_trash_uninstall_site();
			restore_current_blog();
		}

		$category_tag_trash_offset += 100;
	} while ( $category_tag_trash_sites );
} else {
	category_tag_trash_uninstall_site();
}
