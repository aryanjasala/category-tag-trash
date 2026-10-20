<?php
/**
 * Puts trashed terms back.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a term, its meta, its objects, its children and its menu links.
 *
 * Reuses the original term ID when it is still free, so links that store the
 * ID (navigation blocks, shortcodes, widgets) work again.
 */
final class Restorer {

	private const BATCH = 500;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up restoring.
	 *
	 * @param Trash $trash Trashed terms.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Restores several trash items.
	 *
	 * @param int[] $item_ids Trash item IDs.
	 * @return array{restored: int[], notes: string[], errors: string[]} Restored term IDs, notes and errors.
	 */
	public function restore_many( array $item_ids ): array {
		$result = array(
			'restored' => array(),
			'notes'    => array(),
			'errors'   => array(),
		);

		foreach ( $item_ids as $item_id ) {
			$snapshot = $this->trash->get( (int) $item_id );
			if ( $snapshot && ! $this->trash->user_can_manage( $snapshot ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s: term name. */
					__( 'You are not allowed to restore "%s".', 'term-trash' ),
					$snapshot->name
				);
				continue;
			}

			$restored = $this->restore( (int) $item_id );
			if ( is_wp_error( $restored ) ) {
				$result['errors'][] = $restored->get_error_message();
				continue;
			}

			$result['restored'][] = $restored['term_id'];
			$result['notes']      = array_merge( $result['notes'], $restored['notes'] );
		}

		return $result;
	}

	/**
	 * Puts a trashed term back the way it was.
	 *
	 * @param int $item_id Trash item ID.
	 * @return array{term_id: int, notes: string[]}|\WP_Error
	 */
	public function restore( int $item_id ) {
		global $wpdb;

		$snapshot = $this->trash->get( $item_id );
		if ( ! $snapshot ) {
			return new \WP_Error( 'term_trash_missing', __( 'That term is no longer in Term Trash.', 'term-trash' ) );
		}

		$taxonomy = $snapshot->taxonomy;
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'term_trash_taxonomy',
				sprintf(
					/* translators: %s: taxonomy name. */
					__( 'The "%s" taxonomy is not registered, so this term cannot be restored. Activate the plugin or theme that adds it, then try again.', 'term-trash' ),
					$taxonomy
				)
			);
		}

		$notes  = array();
		$parent = $snapshot->parent;
		if ( $parent && ! term_exists( $parent, $taxonomy ) ) {
			$parent = 0;
			/* translators: %s: term name. */
			$notes[] = sprintf( __( '"%s" was restored at the top level because its parent no longer exists.', 'term-trash' ), $snapshot->name );
		}

		$slug = $snapshot->slug;
		if ( get_term_by( 'slug', $slug, $taxonomy ) ) {
			$slug = wp_unique_term_slug(
				$slug,
				(object) array(
					'taxonomy' => $taxonomy,
					'parent'   => $parent,
				)
			);
			/* translators: 1: term name, 2: new slug. */
			$notes[] = sprintf( __( 'Another term now uses the slug of "%1$s", so it was restored as "%2$s".', 'term-trash' ), $snapshot->name, $slug );
		}

		// Inserting directly is the only way to keep the original IDs.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$term_row = array(
			'name'       => $snapshot->name,
			'slug'       => $slug,
			'term_group' => $snapshot->term_group,
		);
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d", $snapshot->term_id ) ) ) {
			$term_row['term_id'] = $snapshot->term_id;
		}

		if ( false === $wpdb->insert( $wpdb->terms, $term_row ) ) {
			return new \WP_Error( 'term_trash_db', __( 'The database refused to restore the term.', 'term-trash' ) );
		}

		$term_id = $term_row['term_id'] ?? (int) $wpdb->insert_id;
		if ( $term_id !== $snapshot->term_id ) {
			/* translators: %s: term name. */
			$notes[] = sprintf( __( '"%s" has a new ID because its old one was taken. Links that stored the old ID need updating.', 'term-trash' ), $snapshot->name );
		}

		$taxonomy_row = array(
			'term_id'     => $term_id,
			'taxonomy'    => $taxonomy,
			'description' => $snapshot->description,
			'parent'      => $parent,
			'count'       => 0,
		);
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $snapshot->term_taxonomy_id ) ) ) {
			$taxonomy_row['term_taxonomy_id'] = $snapshot->term_taxonomy_id;
		}

		if ( false === $wpdb->insert( $wpdb->term_taxonomy, $taxonomy_row ) ) {
			$wpdb->delete( $wpdb->terms, array( 'term_id' => $term_id ) );
			return new \WP_Error( 'term_trash_db', __( 'The database refused to restore the term.', 'term-trash' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$tt_id = $taxonomy_row['term_taxonomy_id'] ?? (int) $wpdb->insert_id;

		foreach ( $snapshot->meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_term_meta( $term_id, wp_slash( (string) $key ), wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		clean_term_cache( $term_id, $taxonomy );

		$object_ids = $this->restore_relationships( $snapshot, $tt_id );
		$this->remove_fallback_term( $snapshot, $term_id );
		$this->restore_children( $snapshot, $term_id );

		if ( $this->restore_menu_links( $snapshot, $term_id ) < count( $snapshot->menu_items ) ) {
			/* translators: %s: term name. */
			$notes[] = sprintf( __( 'Some menu links to "%s" were not restored because their menu no longer exists.', 'term-trash' ), $snapshot->name );
		}

		/** This action is documented in wp-includes/taxonomy.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so other plugins see the restored term.
		do_action( 'created_term', $term_id, $tt_id, $taxonomy, array() );

		/**
		 * Fires after a term is restored from Term Trash.
		 *
		 * @param int      $term_id    Restored term ID.
		 * @param string   $taxonomy   Taxonomy name.
		 * @param Snapshot $snapshot   What was saved when the term was deleted.
		 * @param int[]    $object_ids Objects the term was attached to again.
		 */
		do_action( 'term_trash_restored', $term_id, $taxonomy, $snapshot, $object_ids );

		$this->trash->delete( $item_id );

		return array(
			'term_id' => $term_id,
			'notes'   => $notes,
		);
	}

	/**
	 * Re-attaches the term to its objects, keeping term order.
	 *
	 * Objects deleted while the term was in the trash are skipped.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $tt_id    Restored term taxonomy ID.
	 * @return int[] Object IDs re-attached.
	 */
	private function restore_relationships( Snapshot $snapshot, int $tt_id ): array {
		global $wpdb;

		$object_types = (array) get_taxonomy( $snapshot->taxonomy )->object_type;
		$posts_only   = ! array_diff( $object_types, get_post_types() );
		$restored     = array();

		foreach ( array_chunk( $snapshot->objects, self::BATCH ) as $chunk ) {
			if ( $posts_only ) {
				$ids          = wp_list_pluck( $chunk, 0 );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
				$existing = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )", $ids ) ) );
				$chunk    = array_filter(
					$chunk,
					static function ( array $row ) use ( $existing ): bool {
						return in_array( (int) $row[0], $existing, true );
					}
				);
			}

			if ( ! $chunk ) {
				continue;
			}

			$values = array();
			foreach ( $chunk as $row ) {
				array_push( $values, (int) $row[0], $tt_id, (int) $row[1] );
				$restored[] = (int) $row[0];
			}

			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '(%d, %d, %d)' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id, term_order ) VALUES {$placeholders}", $values ) );
		}

		if ( $restored ) {
			clean_object_term_cache( $restored, $object_types );
		}
		wp_update_term_count_now( array( $tt_id ), $snapshot->taxonomy );

		return $restored;
	}

	/**
	 * Takes back the default term WordPress gave to objects that only had this term.
	 *
	 * Leaves objects alone if someone has changed their terms since.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 */
	private function remove_fallback_term( Snapshot $snapshot, int $term_id ): void {
		$default = $snapshot->default_term;
		if ( ! $default || ! term_exists( $default, $snapshot->taxonomy ) ) {
			return;
		}

		$expected = array( $default, $term_id );
		sort( $expected );

		foreach ( $snapshot->fallback_objects as $object_id ) {
			$current = wp_get_object_terms( $object_id, $snapshot->taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $current ) ) {
				continue;
			}

			$current = array_map( 'intval', $current );
			sort( $current );

			if ( $current === $expected ) {
				wp_remove_object_terms( $object_id, $default, $snapshot->taxonomy );
			}
		}
	}

	/**
	 * Moves child terms back under the restored term.
	 *
	 * WordPress moves them up to the deleted term's parent. Children that were
	 * moved somewhere else since are left alone.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 */
	private function restore_children( Snapshot $snapshot, int $term_id ): void {
		foreach ( $snapshot->children as $child_id ) {
			$child = get_term( $child_id, $snapshot->taxonomy );
			if ( $child instanceof \WP_Term && (int) $child->parent === $snapshot->parent ) {
				wp_update_term( $child_id, $snapshot->taxonomy, array( 'parent' => $term_id ) );
			}
		}
	}

	/**
	 * Recreates menu links to the term, and points their sub-items back at them.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 * @return int Number of menu links restored.
	 */
	private function restore_menu_links( Snapshot $snapshot, int $term_id ): int {
		$restored = 0;

		foreach ( $snapshot->menu_items as $link ) {
			if ( ! wp_get_nav_menu_object( $link['menu_id'] ) ) {
				continue;
			}

			$item_id = wp_update_nav_menu_item(
				$link['menu_id'],
				0,
				array(
					'menu-item-object-id'   => $term_id,
					'menu-item-object'      => $snapshot->taxonomy,
					'menu-item-type'        => 'taxonomy',
					'menu-item-title'       => $link['title'],
					'menu-item-attr-title'  => $link['attr_title'],
					'menu-item-description' => $link['description'],
					'menu-item-target'      => $link['target'],
					'menu-item-classes'     => implode( ' ', $link['classes'] ),
					'menu-item-xfn'         => $link['xfn'],
					'menu-item-position'    => $link['position'],
					'menu-item-parent-id'   => $link['parent_id'] && get_post( $link['parent_id'] ) ? $link['parent_id'] : 0,
					'menu-item-status'      => 'publish',
				)
			);

			if ( is_wp_error( $item_id ) ) {
				continue;
			}

			// Core reads position 0 as "add at the end", but 0 is a real position for
			// items created outside the menu screen, so set it directly.
			if ( (int) get_post_field( 'menu_order', $item_id ) !== (int) $link['position'] ) {
				wp_update_post(
					array(
						'ID'         => $item_id,
						'menu_order' => $link['position'],
					)
				);
			}

			++$restored;

			foreach ( $link['children'] as $child_id ) {
				if ( (int) get_post_meta( $child_id, '_menu_item_menu_item_parent', true ) === (int) $link['id'] ) {
					update_post_meta( $child_id, '_menu_item_menu_item_parent', (string) $item_id );
				}
			}
		}

		return $restored;
	}
}
