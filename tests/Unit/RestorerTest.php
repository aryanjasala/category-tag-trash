<?php
/**
 * Tests for re-attaching objects to a restored term.
 *
 * @package AryanX\CategoryTagTrash
 */

namespace AryanX\CategoryTagTrash\Tests\Unit;

use AryanX\CategoryTagTrash\Restorer;
use AryanX\CategoryTagTrash\Settings;
use AryanX\CategoryTagTrash\Snapshot;
use AryanX\CategoryTagTrash\Trash;
use Brain\Monkey\Functions;

final class RestorerTest extends TestCase {

	/**
	 * The $wpdb in place before the test.
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->previous_wpdb = $wpdb;
		$wpdb                = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $term_relationships = 'wp_term_relationships';
			/** @var string */
			public $term_taxonomy = 'wp_term_taxonomy';
			/** @var string[] */
			public $queries = array();

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function query( $query ) {
				$this->queries[] = $query;
				return 1;
			}

			public function get_col( $query ) {
				// Every object still exists.
				return array( '40', '41' );
			}

			public function get_results( $query, $output = null ) {
				return array();
			}
		};
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_the_term_cache_is_cleaned_once_per_object_type(): void {
		Functions\when( 'wp_list_pluck' )->alias( 'array_column' );
		Functions\when( 'get_taxonomy' )->justReturn( (object) array( 'object_type' => array( 'post', 'page' ) ) );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => 'post',
				'page' => 'page',
			)
		);

		$cleaned = array();
		Functions\when( 'clean_object_term_cache' )->alias(
			static function ( $object_ids, $object_type ) use ( &$cleaned ) {
				$cleaned[] = array( $object_ids, $object_type );
			}
		);

		$snapshot           = new Snapshot();
		$snapshot->taxonomy = 'category';

		$restorer = new Restorer( new Trash( new Settings() ) );
		$attach   = new \ReflectionMethod( $restorer, 'attach' );
		$attach->setAccessible( true );
		$ids = $attach->invoke( $restorer, $snapshot, 12, 13, array( array( 40, 0 ), array( 41, 2 ) ) );

		$this->assertSame( array( 40, 41 ), $ids );
		// A string each time: callbacks on the clean_object_term_cache action expect one.
		$this->assertSame(
			array(
				array( array( 40, 41 ), 'post' ),
				array( array( 40, 41 ), 'page' ),
			),
			$cleaned
		);
	}
}
