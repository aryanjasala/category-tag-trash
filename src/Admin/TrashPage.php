<?php
/**
 * Tools > Term Trash.
 *
 * @package AryanX\TermTrash
 */

namespace AryanX\TermTrash\Admin;

use AryanX\TermTrash\Purger;
use AryanX\TermTrash\Restorer;
use AryanX\TermTrash\Service;
use AryanX\TermTrash\Settings;
use AryanX\TermTrash\Trash;
use const AryanX\TermTrash\PLUGIN_FILE;
use const AryanX\TermTrash\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The trash screen: list, bulk actions, empty trash and the retention setting.
 */
final class TrashPage implements Service {

	public const SLUG   = 'term-trash';
	public const ACTION = 'term_trash';

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
	 * Purging and emptying.
	 *
	 * @var Purger
	 */
	private Purger $purger;

	/**
	 * Sets up the screen.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 * @param Purger   $purger   Purging and emptying.
	 */
	public function __construct( Trash $trash, Restorer $restorer, Purger $purger ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
		$this->purger   = $purger;
	}

	/**
	 * Capability needed to open Tools > Term Trash.
	 *
	 * Each restore and delete still checks the term's own taxonomy.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to open Tools > Term Trash.
		 *
		 * @param string $capability Capability. Default manage_categories.
		 */
		return (string) apply_filters( 'term_trash_capability', 'manage_categories' );
	}

	/**
	 * Adds the menu entry and the action handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * URL that runs an action on trash items.
	 *
	 * @param string $task     restore or delete.
	 * @param int[]  $item_ids Trash item IDs.
	 * @param string $back     Where to return afterwards; the trash screen by default.
	 * @return string
	 */
	public static function action_url( string $task, array $item_ids, string $back = '' ): string {
		$ids = implode( ',', array_map( 'intval', $item_ids ) );

		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => self::ACTION,
						'task'   => $task,
						'items'  => $ids,
						'back'   => $back ? rawurlencode( $back ) : '',
					)
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $task . '_' . $ids
		);
	}

	/**
	 * Registers Tools > Term Trash, and handles bulk actions before output.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Term Trash', 'term-trash' ),
			__( 'Term Trash', 'term-trash' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "load-{$hook}", array( $this, 'handle_bulk_action' ) );
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
		}
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_styles(): void {
		wp_enqueue_style( 'term-trash-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new TrashListTable( $this->trash );
		$table->prepare_items();
		$days = $this->trash->settings()->retention_days();
		?>
		<div class="wrap tt-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Term Trash', 'term-trash' ); ?></h1>
			<?php if ( $table->has_items() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-inline">
					<?php wp_nonce_field( self::ACTION . '_empty' ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="task" value="empty">
					<button type="submit" class="page-title-action tt-empty" onclick="return window.confirm( '<?php echo esc_js( __( 'Delete everything in Term Trash for good? This cannot be undone.', 'term-trash' ) ); ?>' );"><?php esc_html_e( 'Empty trash', 'term-trash' ); ?></button>
				</form>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<div class="tt-intro">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of days. */
							_n( 'Deleted categories, tags and other terms are kept here for %d day, then deleted for good.', 'Deleted categories, tags and other terms are kept here for %d days, then deleted for good.', $days, 'term-trash' ),
							$days
						)
					);
					?>
				</p>
				<?php $this->render_retention_form( $days ); ?>
			</div>

			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( $table->current_taxonomy() ) : ?>
					<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $table->current_taxonomy() ); ?>">
				<?php endif; ?>
				<?php $table->search_box( __( 'Search trash', 'term-trash' ), 'term-trash' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints the "Keep deleted terms for" setting.
	 *
	 * @param int $days Current retention in days.
	 */
	private function render_retention_form( int $days ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-retention">
			<?php wp_nonce_field( self::ACTION . '_settings' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="settings">
			<label for="tt-retention"><?php esc_html_e( 'Keep deleted terms for', 'term-trash' ); ?></label>
			<select id="tt-retention" name="retention_days">
				<?php foreach ( Settings::retention_choices() as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $days, $option ); ?>>
						<?php
						/* translators: %d: number of days. */
						echo esc_html( sprintf( _n( '%d day', '%d days', $option, 'term-trash' ), $option ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'term-trash' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$restored = isset( $_GET['tt_restored'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['tt_restored'] ) ) ) ) ) : array();
		$deleted  = isset( $_GET['tt_deleted'] ) ? absint( $_GET['tt_deleted'] ) : 0;
		$saved    = isset( $_GET['tt_saved'] );
		$notes    = isset( $_GET['tt_notes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['tt_notes'] ) ) : array();
		$errors   = isset( $_GET['tt_errors'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['tt_errors'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::print_restored_notice( $restored, $notes );

		if ( $deleted ) {
			/* translators: %d: number of terms. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d term deleted for good.', '%d terms deleted for good.', $deleted, 'term-trash' ), $deleted ) ) );
		}

		if ( $saved ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved.', 'term-trash' ) );
		}

		foreach ( $errors as $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Prints "Restored X" with links to the restored terms, plus any notes.
	 *
	 * @param int[]    $term_ids Restored term IDs.
	 * @param string[] $notes    Notes about anything that changed.
	 */
	public static function print_restored_notice( array $term_ids, array $notes ): void {
		$links = array();
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_term_link( $term ) ), esc_html( $term->name ) );
			}
		}

		if ( ! $links ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible tt-restored"><p>';
		/* translators: %s: list of restored term names. */
		printf( esc_html__( 'Restored %s.', 'term-trash' ), wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ) );
		echo '</p>';
		foreach ( $notes as $note ) {
			echo '<p>' . esc_html( $note ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Runs list table bulk actions, then redirects to a clean URL.
	 */
	public function handle_bulk_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below once an action is present.
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! $action && isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		$item_ids = isset( $_REQUEST['items'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['items'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'restore', 'delete' ), true ) ) {
			return;
		}

		check_admin_referer( 'bulk-term_trash_items' );
		$this->run( $action, $item_ids, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * Handles row actions, Undo links, Empty trash and the retention setting.
	 */
	public function handle_action(): void {
		$task  = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';
		$items = isset( $_REQUEST['items'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['items'] ) ) : '';
		$back  = admin_url( 'tools.php?page=' . self::SLUG );

		if ( 'settings' === $task ) {
			check_admin_referer( self::ACTION . '_settings' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to change this setting.', 'term-trash' ), 403 );
			}
			$this->trash->settings()->set_retention_days( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30 );
			wp_safe_redirect( add_query_arg( 'tt_saved', '1', $back ) );
			exit;
		}

		if ( 'empty' === $task ) {
			check_admin_referer( self::ACTION . '_empty' );
			// Site admins empty everything, the rest in the background. Others only what they may manage.
			$result = $this->purger->empty_trash( 200, current_user_can( 'manage_options' ) ? null : array( $this->trash, 'user_can_manage' ) );
			$args   = array( 'tt_deleted' => $result['deleted'] );
			if ( $result['remaining'] && current_user_can( 'manage_options' ) ) {
				$args['tt_notes'] = array(
					rawurlencode(
						sprintf(
							/* translators: %s: number of trashed terms. */
							_n( 'The last %s term is being deleted in the background.', 'The other %s terms are being deleted in the background.', $result['remaining'], 'term-trash' ),
							number_format_i18n( $result['remaining'] )
						)
					),
				);
			}
			wp_safe_redirect( add_query_arg( array_filter( $args ), $back ) );
			exit;
		}

		check_admin_referer( self::ACTION . '_' . $task . '_' . $items );

		if ( isset( $_REQUEST['back'] ) ) {
			$back = wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['back'] ) ), $back );
		}

		$this->run( $task, array_map( 'absint', explode( ',', $items ) ), $back );
	}

	/**
	 * Restores or deletes items, then redirects with the result.
	 *
	 * @param string $task     restore or delete.
	 * @param int[]  $item_ids Trash item IDs.
	 * @param string $back     URL to return to.
	 */
	private function run( string $task, array $item_ids, string $back ): void {
		$args = array();

		if ( 'restore' === $task ) {
			$result = $this->restorer->restore_many( $item_ids );
			$args   = array(
				'tt_restored' => implode( ',', $result['restored'] ),
				'tt_notes'    => array_map( 'rawurlencode', $result['notes'] ),
				'tt_errors'   => array_map( 'rawurlencode', $result['errors'] ),
			);
		} elseif ( 'delete' === $task ) {
			$deleted = 0;
			foreach ( $item_ids as $item_id ) {
				$snapshot = $this->trash->get( $item_id );
				if ( $snapshot && $this->trash->user_can_manage( $snapshot ) ) {
					$deleted += (int) $this->trash->delete( $item_id );
				}
			}
			$args = array( 'tt_deleted' => $deleted );
		}

		wp_safe_redirect( add_query_arg( array_filter( $args ), $back ) );
		exit;
	}
}
