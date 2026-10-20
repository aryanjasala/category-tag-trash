<?php
/**
 * Tests for snapshots, retention and which taxonomies are kept.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash\Tests\Unit;

use AryanX\TermTrash\Settings;
use AryanX\TermTrash\Snapshot;
use AryanX\TermTrash\Trash;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class TermTrashTest extends TestCase {

	public function test_snapshot_round_trips_through_stored_data(): void {
		Functions\when( 'wp_list_pluck' )->alias( 'array_column' );

		$snapshot                   = new Snapshot();
		$snapshot->term_id          = 12;
		$snapshot->term_taxonomy_id = 13;
		$snapshot->taxonomy         = 'category';
		$snapshot->name             = 'Recipes';
		$snapshot->slug             = 'recipes';
		$snapshot->objects          = array( array( 40, 0 ), array( 41, 2 ) );
		$snapshot->children         = array( 15 );
		$snapshot->meta             = array( 'color' => array( 'red' ) );

		$copy = Snapshot::from_array( $snapshot->to_array() );

		$this->assertEquals( $snapshot, $copy );
		$this->assertSame( array( 40, 41 ), $copy->object_ids() );
	}

	public function test_snapshot_casts_stored_values_to_their_types(): void {
		$snapshot = Snapshot::from_array(
			array(
				'term_id'  => '12',
				'taxonomy' => 'post_tag',
				'parent'   => '3',
				'name'     => 5,
			)
		);

		$this->assertSame( 12, $snapshot->term_id );
		$this->assertSame( 3, $snapshot->parent );
		$this->assertSame( '5', $snapshot->name );
	}

	public function test_data_without_a_term_or_taxonomy_is_not_a_snapshot(): void {
		$this->assertNull( Snapshot::from_array( array( 'taxonomy' => 'category' ) ) );
		$this->assertNull( Snapshot::from_array( array( 'term_id' => 4 ) ) );
	}

	public function test_retention_defaults_to_30_days(): void {
		$this->assertSame( 30, ( new Settings() )->retention_days() );
	}

	public function test_retention_uses_the_saved_value_and_the_filter_wins(): void {
		$this->options[ Settings::OPTION ] = array( 'retention_days' => 90 );
		$this->assertSame( 90, ( new Settings() )->retention_days() );

		Filters\expectApplied( 'term_trash_retention_days' )->once()->with( 90 )->andReturn( 7 );
		$this->assertSame( 7, ( new Settings() )->retention_days() );
	}

	public function test_only_offered_retention_periods_are_saved(): void {
		$settings = new Settings();

		$settings->set_retention_days( 45 );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->options );

		$settings->set_retention_days( 365 );
		$this->assertSame( 365, $settings->retention_days() );
	}

	public function test_taxonomies_with_a_screen_are_kept_but_menus_are_not(): void {
		Functions\when( 'get_taxonomy' )->alias(
			static function ( $taxonomy ) {
				$known = array(
					'category'    => true,
					'nav_menu'    => true,
					'post_format' => false,
				);
				return isset( $known[ $taxonomy ] ) ? (object) array( 'show_ui' => $known[ $taxonomy ] ) : false;
			}
		);
		$trash = new Trash( new Settings() );

		$this->assertTrue( $trash->supports( 'category' ) );
		$this->assertFalse( $trash->supports( 'nav_menu' ) );
		$this->assertFalse( $trash->supports( 'post_format' ) );
		$this->assertFalse( $trash->supports( 'not_registered' ) );
	}
}
