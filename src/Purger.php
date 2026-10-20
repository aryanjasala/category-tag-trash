<?php
/**
 * Deletes trashed terms after the retention period.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cron task that empties old items from the trash.
 */
final class Purger implements Service {

	public const HOOK = 'term_trash_purge';

	private const BATCH       = 100;
	private const MAX_BATCHES = 20;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up purging.
	 *
	 * @param Trash $trash Trashed terms.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks the cron task, and keeps it scheduled.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily purge. Runs on activation and in wp-admin.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the daily purge. Runs on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 */
	public function run(): void {
		$this->purge();
	}

	/**
	 * Deletes items older than the retention period.
	 *
	 * @return int Number of items deleted.
	 */
	public function purge(): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $this->trash->settings()->retention_days() * DAY_IN_SECONDS );
		$deleted = 0;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$expired = get_posts(
				array(
					'post_type'   => Trash::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => self::BATCH,
					'fields'      => 'ids',
					'date_query'  => array(
						array(
							'column' => 'post_date_gmt',
							'before' => $cutoff,
						),
					),
				)
			);

			if ( ! $expired ) {
				break;
			}

			foreach ( $expired as $item_id ) {
				$deleted += (int) $this->trash->delete( (int) $item_id );
			}
		}

		return $deleted;
	}
}
