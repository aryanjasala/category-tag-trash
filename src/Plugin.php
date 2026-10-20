<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash;

use AryanX\TermTrash\Admin\TermScreen;
use AryanX\TermTrash\Admin\TrashPage;
use AryanX\TermTrash\Cli\Command;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Puts terms back.
	 *
	 * @var Restorer
	 */
	private Restorer $restorer;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->trash    = new Trash( new Settings() );
		$this->restorer = new Restorer( $this->trash );
	}

	/**
	 * Trashed terms, for other plugins.
	 *
	 * @return Trash
	 */
	public function trash(): Trash {
		return $this->trash;
	}

	/**
	 * Restore logic, for other plugins.
	 *
	 * @return Restorer
	 */
	public function restorer(): Restorer {
		return $this->restorer;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->trash,
			new Snapshotter( $this->trash ),
			new Purger( $this->trash ),
			new TrashPage( $this->trash, $this->restorer ),
			new TermScreen( $this->trash, $this->restorer ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'term-trash', new Command( $this->trash, $this->restorer ) );
		}
	}
}
