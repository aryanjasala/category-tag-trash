<?php
/**
 * `wp term-trash` commands.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash\Cli;

use AryanX\TermTrash\Restorer;
use AryanX\TermTrash\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, restores and deletes trashed terms.
 */
final class Command {

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
	 * Sets up the command.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 */
	public function __construct( Trash $trash, Restorer $restorer ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
	}

	/**
	 * Lists trashed terms.
	 *
	 * ## OPTIONS
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Only this taxonomy.
	 *
	 * [--format=<format>]
	 * : table, csv, json, count or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$ids    = $this->trash->find( array( 'taxonomy' => $assoc_args['taxonomy'] ?? '' ) )['ids'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		$rows = array();
		foreach ( $ids as $item_id ) {
			$snapshot = $this->trash->get( $item_id );
			if ( $snapshot ) {
				$rows[] = array(
					'id'       => $item_id,
					'term_id'  => $snapshot->term_id,
					'name'     => $snapshot->name,
					'taxonomy' => $snapshot->taxonomy,
					'objects'  => count( $snapshot->objects ),
					'deleted'  => get_post_field( 'post_date_gmt', $item_id ),
				);
			}
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'term_id', 'name', 'taxonomy', 'objects', 'deleted' ) );
	}

	/**
	 * Restores trashed terms.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Trash item IDs from `wp term-trash list`.
	 *
	 * @param array $args Positional arguments.
	 */
	public function restore( array $args ): void {
		foreach ( $args as $item_id ) {
			$result = $this->restorer->restore( (int) $item_id );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $item_id . ': ' . $result->get_error_message() );
				continue;
			}

			\WP_CLI::success( 'Restored term ' . $result['term_id'] . '.' );
			foreach ( $result['notes'] as $note ) {
				\WP_CLI::line( '  ' . $note );
			}
		}
	}

	/**
	 * Deletes trashed terms for good.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Trash item IDs from `wp term-trash list`.
	 *
	 * [--all]
	 * : Empty the whole trash.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$ids = empty( $assoc_args['all'] ) ? array_map( 'intval', $args ) : $this->trash->find()['ids'];

		foreach ( $ids as $item_id ) {
			if ( $this->trash->delete( $item_id ) ) {
				\WP_CLI::success( 'Deleted trash item ' . $item_id . '.' );
			}
		}
	}
}
