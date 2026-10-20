<?php
/**
 * Everything about a term at the moment it was deleted.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Value object stored with each trashed term.
 */
final class Snapshot {

	/**
	 * Original term ID.
	 *
	 * @var int
	 */
	public int $term_id = 0;

	/**
	 * Original term taxonomy ID.
	 *
	 * @var int
	 */
	public int $term_taxonomy_id = 0;

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	public string $taxonomy = '';

	/**
	 * Term name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Term slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Term description.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Parent term ID.
	 *
	 * @var int
	 */
	public int $parent = 0;

	/**
	 * Term group.
	 *
	 * @var int
	 */
	public int $term_group = 0;

	/**
	 * Raw term meta: key => list of stored values.
	 *
	 * @var array<string, string[]>
	 */
	public array $meta = array();

	/**
	 * Attached objects as [ object ID, term order ] pairs.
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	public array $objects = array();

	/**
	 * Term WordPress gave to objects left with no term, or 0.
	 *
	 * @var int
	 */
	public int $default_term = 0;

	/**
	 * Objects that only had this term, so got the default term instead.
	 *
	 * @var int[]
	 */
	public array $fallback_objects = array();

	/**
	 * Direct child term IDs.
	 *
	 * @var int[]
	 */
	public array $children = array();

	/**
	 * Menu links to the term.
	 *
	 * @var array[]
	 */
	public array $menu_items = array();

	/**
	 * Builds a snapshot from stored data.
	 *
	 * @param array $data Stored data.
	 * @return Snapshot|null Null when the data is not a snapshot.
	 */
	public static function from_array( array $data ): ?Snapshot {
		if ( empty( $data['term_id'] ) || empty( $data['taxonomy'] ) ) {
			return null;
		}

		$snapshot = new self();
		foreach ( get_object_vars( $snapshot ) as $key => $default ) {
			if ( array_key_exists( $key, $data ) ) {
				$value            = $data[ $key ];
				$snapshot->{$key} = is_int( $default ) ? (int) $value : ( is_array( $default ) ? (array) $value : (string) $value );
			}
		}

		return $snapshot;
	}

	/**
	 * Data to store.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array( 'version' => 2 ) + get_object_vars( $this );
	}

	/**
	 * IDs of the attached objects.
	 *
	 * @return int[]
	 */
	public function object_ids(): array {
		return array_map( 'intval', wp_list_pluck( $this->objects, 0 ) );
	}
}
