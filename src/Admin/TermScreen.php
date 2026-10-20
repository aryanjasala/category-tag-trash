<?php
/**
 * Undo notices and the trash link on Categories, Tags and other term screens.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash\Admin;

use AryanX\TermTrash\Restorer;
use AryanX\TermTrash\Service;
use AryanX\TermTrash\Snapshotter;
use AryanX\TermTrash\Trash;
use const AryanX\TermTrash\PLUGIN_FILE;
use const AryanX\TermTrash\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Offers Undo right after a term is deleted.
 *
 * Single deletes on term screens happen over ajax, so a small script shows
 * the Undo notice. Bulk deletes reload the page, so the notice comes from a
 * short-lived per-user list of what was just deleted.
 */
final class TermScreen implements Service {

	private const AJAX_UNDO = 'term_trash_undo';

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Restore logic.
	 *
	 * @var Restorer
	 */
	private Restorer $restorer;

	/**
	 * Sets up the term screen additions.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 */
	public function __construct( Trash $trash, Restorer $restorer ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_table_notes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'bulk_undo_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_UNDO, array( $this, 'ajax_undo' ) );
	}

	/**
	 * Adds the Term Trash link under each supported term list.
	 */
	public function add_table_notes(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			if ( $this->trash->supports( $taxonomy ) ) {
				add_action( "after-{$taxonomy}-table", array( $this, 'table_note' ) );
			}
		}
	}

	/**
	 * Prints a link to Term Trash below the terms table.
	 *
	 * @param string $taxonomy Taxonomy of the screen.
	 */
	public function table_note( string $taxonomy ): void {
		$count = $this->trash->count_by_taxonomy()[ $taxonomy ] ?? 0;

		printf(
			'<p class="term-trash-note"><span class="dashicons dashicons-trash" aria-hidden="true"></span> %s <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'Anything deleted here stays in Term Trash for %d day.', 'Anything deleted here stays in Term Trash for %d days.', $this->trash->settings()->retention_days(), 'term-trash' ),
					$this->trash->settings()->retention_days()
				)
			),
			esc_url( add_query_arg( 'taxonomy', $taxonomy, admin_url( 'tools.php?page=' . TrashPage::SLUG ) ) ),
			esc_html(
				sprintf(
					/* translators: %d: number of trashed terms. */
					_n( 'View Term Trash (%d)', 'View Term Trash (%d)', $count, 'term-trash' ),
					$count
				)
			)
		);
	}

	/**
	 * Loads the Undo script on term list screens.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		$screen = get_current_screen();
		if ( 'edit-tags.php' !== $hook_suffix || ! $screen || ! $this->trash->supports( (string) $screen->taxonomy ) ) {
			return;
		}

		wp_enqueue_script( 'term-trash-term-screen', plugins_url( 'assets/term-screen.js', PLUGIN_FILE ), array( 'jquery', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'term-trash-term-screen',
			'termTrashScreen',
			array(
				'action' => self::AJAX_UNDO,
				'nonce'  => wp_create_nonce( self::AJAX_UNDO ),
				'i18n'   => array(
					/* translators: %s: term name. */
					'moved'     => __( '"%s" moved to Term Trash.', 'term-trash' ),
					'undo'      => __( 'Undo', 'term-trash' ),
					'restoring' => __( 'Restoring…', 'term-trash' ),
					/* translators: %s: term name. */
					'restored'  => __( '"%s" restored.', 'term-trash' ),
					'failed'    => __( 'Could not restore the term.', 'term-trash' ),
				),
			)
		);
		wp_enqueue_style( 'term-trash-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Restores the term deleted over ajax a moment ago.
	 */
	public function ajax_undo(): void {
		check_ajax_referer( self::AJAX_UNDO );

		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$item_id  = $this->trash->find_latest_for_term( $term_id, $taxonomy );
		$snapshot = $item_id ? $this->trash->get( $item_id ) : null;

		if ( ! $snapshot ) {
			wp_send_json_error( array( 'message' => __( 'That term is no longer in Term Trash.', 'term-trash' ) ), 404 );
		}

		if ( ! $this->trash->user_can_manage( $snapshot ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to restore this term.', 'term-trash' ) ), 403 );
		}

		$result = $this->restorer->restore( $item_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'term_id' => $result['term_id'],
				'name'    => $snapshot->name,
				'notes'   => $result['notes'],
			)
		);
	}

	/**
	 * After a bulk delete, offers to undo all of it.
	 */
	public function bulk_undo_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-tags' !== $screen->base ) {
			return;
		}

		$user_id  = get_current_user_id();
		$item_ids = array_filter( array_map( 'intval', (array) get_transient( Snapshotter::undo_key( $user_id ) ) ) );
		delete_transient( Snapshotter::undo_key( $user_id ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$restored = isset( $_GET['tt_restored'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['tt_restored'] ) ) ) ) ) : array();
		$notes    = isset( $_GET['tt_notes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['tt_notes'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		TrashPage::print_restored_notice( $restored, $notes );

		if ( ! $item_ids ) {
			return;
		}

		$back = add_query_arg( 'taxonomy', $screen->taxonomy, admin_url( 'edit-tags.php' ) );
		if ( 'post' !== $screen->post_type ) {
			$back = add_query_arg( 'post_type', $screen->post_type, $back );
		}

		printf(
			'<div class="notice notice-success is-dismissible term-trash-undo"><p>%s <a href="%s" class="button button-small">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of terms. */
					_n( '%d term moved to Term Trash.', '%d terms moved to Term Trash.', count( $item_ids ), 'term-trash' ),
					count( $item_ids )
				)
			),
			esc_url( TrashPage::action_url( 'restore', $item_ids, $back ) ),
			esc_html__( 'Undo', 'term-trash' )
		);
	}
}
