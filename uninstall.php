<?php
/**
 * Deletes everything in Term Trash and the purge schedule.
 *
 * @package TermTrash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'term_trash_purge' );
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
		wp_delete_post( $term_trash_item, true );
	}
} while ( $term_trash_items );
