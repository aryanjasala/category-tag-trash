<?php
/**
 * Storage for trashed terms.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each trashed term as a hidden post with its snapshot in post meta.
 */
final class Trash implements Service {

	public const POST_TYPE    = 'term_trash_item';
	public const SNAPSHOT_KEY = '_term_trash_snapshot';
	public const TAXONOMY_KEY = '_term_trash_taxonomy';
	public const TERM_KEY     = '_term_trash_term_id';

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up storage.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hidden post type.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the post type that holds trashed terms.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'      => __( 'Term Trash', 'term-trash' ),
				'public'     => false,
				'show_ui'    => false,
				'rewrite'    => false,
				'query_var'  => false,
				'can_export' => false,
				'supports'   => array( 'title', 'author' ),
			)
		);
	}

	/**
	 * Saved settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Taxonomies whose deleted terms are kept.
	 *
	 * Only taxonomies people manage on a screen. Menus are excluded because
	 * deleting a menu removes its items before the menu term itself.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function supports( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );
		$keep   = $object && $object->show_ui && 'nav_menu' !== $taxonomy;

		/**
		 * Filters whether deleted terms of a taxonomy go to Term Trash.
		 *
		 * @param bool   $keep     Whether to keep deleted terms.
		 * @param string $taxonomy Taxonomy name.
		 */
		return (bool) apply_filters( 'term_trash_supports_taxonomy', $keep, $taxonomy );
	}

	/**
	 * Stores a snapshot.
	 *
	 * @param Snapshot $snapshot Deleted term.
	 * @return int Trash item ID, or 0 on failure.
	 */
	public function add( Snapshot $snapshot ): int {
		$item_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $snapshot->name ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $item_id ) ) {
			return 0;
		}

		update_post_meta( $item_id, self::SNAPSHOT_KEY, wp_slash( $snapshot->to_array() ) );
		update_post_meta( $item_id, self::TAXONOMY_KEY, $snapshot->taxonomy );
		update_post_meta( $item_id, self::TERM_KEY, $snapshot->term_id );

		return (int) $item_id;
	}

	/**
	 * Snapshot stored in a trash item.
	 *
	 * @param int $item_id Trash item ID.
	 * @return Snapshot|null
	 */
	public function get( int $item_id ): ?Snapshot {
		if ( self::POST_TYPE !== get_post_type( $item_id ) ) {
			return null;
		}

		$data = get_post_meta( $item_id, self::SNAPSHOT_KEY, true );

		return is_array( $data ) ? Snapshot::from_array( $data ) : null;
	}

	/**
	 * Trash item IDs, newest first.
	 *
	 * @param array $args {
	 *     Optional filters.
	 *
	 *     @type string $taxonomy Only this taxonomy.
	 *     @type int    $term_id  Only this original term ID.
	 *     @type string $search   Name contains this text.
	 *     @type int    $per_page Items per page, -1 for all. Default -1.
	 *     @type int    $page     Page number. Default 1.
	 * }
	 * @return array{ids: int[], total: int}
	 */
	public function find( array $args = array() ): array {
		$meta_query = array();
		if ( ! empty( $args['taxonomy'] ) ) {
			$meta_query[] = array(
				'key'   => self::TAXONOMY_KEY,
				'value' => $args['taxonomy'],
			);
		}
		if ( ! empty( $args['term_id'] ) ) {
			$meta_query[] = array(
				'key'   => self::TERM_KEY,
				'value' => (int) $args['term_id'],
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'posts_per_page' => $args['per_page'] ?? -1,
				'paged'          => $args['page'] ?? 1,
				's'              => $args['search'] ?? '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, private post type.
				'meta_query'     => $meta_query,
			)
		);

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Number of trashed terms per taxonomy.
	 *
	 * @return array<string, int>
	 */
	public function count_by_taxonomy(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grouped count, no API for it.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS taxonomy, COUNT(*) AS total FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s GROUP BY pm.meta_value",
				self::TAXONOMY_KEY,
				self::POST_TYPE
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->taxonomy ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Most recent trash item for an original term.
	 *
	 * @param int    $term_id  Original term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int Trash item ID, or 0.
	 */
	public function find_latest_for_term( int $term_id, string $taxonomy ): int {
		$found = $this->find(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'per_page' => 1,
			)
		);

		return $found['ids'][0] ?? 0;
	}

	/**
	 * Deletes a trash item for good.
	 *
	 * @param int $item_id Trash item ID.
	 * @return bool
	 */
	public function delete( int $item_id ): bool {
		return self::POST_TYPE === get_post_type( $item_id ) && (bool) wp_delete_post( $item_id, true );
	}

	/**
	 * Whether the current user may restore or delete a trashed term.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @return bool
	 */
	public function user_can_manage( Snapshot $snapshot ): bool {
		$taxonomy = get_taxonomy( $snapshot->taxonomy );

		return $taxonomy ? current_user_can( $taxonomy->cap->manage_terms ) : current_user_can( 'manage_options' );
	}
}
