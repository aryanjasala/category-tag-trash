<?php
/**
 * Saved settings.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash;

defined( 'ABSPATH' ) || exit;

/**
 * How long trashed terms are kept.
 */
final class Settings {

	public const OPTION         = 'term_trash_settings';
	public const RETENTION_DAYS = array( 7, 30, 90, 365 );
	private const DEFAULT_DAYS  = 30;

	/**
	 * Days a trashed term is kept before it is deleted for good.
	 *
	 * @return int
	 */
	public function retention_days(): int {
		$saved = get_option( self::OPTION, array() );
		$days  = is_array( $saved ) && isset( $saved['retention_days'] ) ? (int) $saved['retention_days'] : self::DEFAULT_DAYS;

		/**
		 * Filters how many days trashed terms are kept.
		 *
		 * @param int $days Number of days.
		 */
		return max( 1, (int) apply_filters( 'term_trash_retention_days', $days ) );
	}

	/**
	 * Saves the retention period.
	 *
	 * @param int $days Number of days, one of RETENTION_DAYS.
	 */
	public function set_retention_days( int $days ): void {
		if ( in_array( $days, self::RETENTION_DAYS, true ) ) {
			update_option( self::OPTION, array( 'retention_days' => $days ), false );
		}
	}
}
