<?php
/**
 * Deletes everything in Term Trash, its settings and its schedules, on every site.
 *
 * @package TermTrash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Term Trash data from the current site.
 */
function term_trash_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'term_trash_purge' );
	wp_clear_scheduled_hook( 'term_trash_empty' );
	delete_option( 'term_trash_settings' );

	do {
		$term_trash_items = get_posts(
			array(
				'post_type'   => 'term_trash_item',
				'post_status' => 'any',
				'numberposts' => 100,
				'fields'      => 'ids',
			)
		);

		foreach ( $term_trash_items as $term_trash_item ) {
			wp_clear_scheduled_hook( 'term_trash_restore_batch', array( $term_trash_item ) );
			wp_delete_post( $term_trash_item, true );
		}
	} while ( $term_trash_items );

	// Locks left behind by an interrupted background restore.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'term_trash_lock_' ) . '%' ) );
}

if ( is_multisite() ) {
	$term_trash_offset = 0;
	do {
		$term_trash_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $term_trash_offset,
			)
		);

		foreach ( $term_trash_sites as $term_trash_site ) {
			switch_to_blog( $term_trash_site );
			term_trash_uninstall_site();
			restore_current_blog();
		}

		$term_trash_offset += 100;
	} while ( $term_trash_sites );
} else {
	term_trash_uninstall_site();
}
