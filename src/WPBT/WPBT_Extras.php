<?php
/**
 * WordPress Beta Tester
 *
 * @package WordPress_Beta_Tester
 * @author Andy Fragen, original author Peter Westwood.
 * @license GPLv2+
 * @copyright 2009-2016 Peter Westwood (email : peter.westwood@ftwr.co.uk)
 */

/**
 * WPBT_Extras
 */
class WPBT_Extras {
	/**
	 * Placeholder for saved options.
	 *
	 * @var array
	 */
	protected static $options;

	/**
	 * Holds the WP_Beta_Tester instance.
	 *
	 * @var WP_Beta_Tester
	 */
	protected $wp_beta_tester;

	/**
	 * Constructor.
	 *
	 * @param  WP_Beta_Tester $wp_beta_tester Instance of class WP_Beta_Tester.
	 * @param  array          $options        Site options.
	 * @return void
	 */
	public function __construct( WP_Beta_Tester $wp_beta_tester, $options ) {
		$this->wp_beta_tester = $wp_beta_tester;
		self::$options        = $options;
	}

	/**
	 * Initialize Extras.
	 *
	 * @return void
	 */
	public function init() {
		$this->load_hooks();
		$this->skip_autoupdate_email();
		$this->skip_bundled_files_on_upgrade();
	}

	/**
	 * Load hooks.
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_filter( 'wp_beta_tester_add_settings_tabs', array( $this, 'add_settings_tab' ) );
		add_action( 'wp_beta_tester_add_settings', array( $this, 'add_settings' ) );
		add_action( 'wp_beta_tester_add_admin_page', array( $this, 'add_admin_page' ), 10, 2 );
		add_action( 'wp_beta_tester_update_settings', array( $this, 'save_settings' ) );
	}

	/**
	 * Add class settings tab.
	 *
	 * @param  array $tabs Settings tabs.
	 * @return array
	 */
	public function add_settings_tab( $tabs ) {
		return array_merge( $tabs, array( 'wp_beta_tester_extras' => esc_html__( 'Extra Settings', 'wordpress-beta-tester' ) ) );
	}

	/**
	 * Setup Settings API.
	 *
	 * @return void
	 */
	public function add_settings() {
		register_setting(
			'wp_beta_tester',
			'wp_beta_tester_extras',
			array( 'WPBT_Settings', 'sanitize' )
		);

		add_settings_section(
			'wp_beta_tester_email',
			null,
			null,
			'wp_beta_tester_extras'
		);

		add_settings_field(
			'skip_autoupdate_email',
			null,
			array( 'WPBT_Settings', 'checkbox_setting' ),
			'wp_beta_tester_extras',
			'wp_beta_tester_email',
			array(
				'id'          => 'skip_autoupdate_email',
				'title'       => esc_html__( 'Skip successful autoupdate emails.', 'wordpress-beta-tester' ),
				'description' => esc_html__( 'Disable sending emails to the admin user for successful autoupdates. Only emails indicating failures of the autoupdate process are sent.', 'wordpress-beta-tester' ),
			)
		);

		add_settings_section(
			'wp_beta_tester_bundled',
			null,
			null,
			'wp_beta_tester_extras'
		);

		add_settings_field(
			'skip_bundled_files_on_upgrade',
			null,
			array( 'WPBT_Settings', 'checkbox_setting' ),
			'wp_beta_tester_extras',
			'wp_beta_tester_bundled',
			array(
				'id'          => 'skip_bundled_files_on_upgrade',
				'title'       => esc_html__( 'Skip bundled plugins and themes on upgrade.', 'wordpress-beta-tester' ),
				'description' => esc_html__( 'Bundled plugins and themes are skipped on Core Upgrade.', 'wordpress-beta-tester' ),
			)
		);
	}

	/**
	 * Save settings.
	 *
	 * @param  mixed $post_data $_POST data.
	 * @return void
	 */
	public function save_settings( $post_data ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_beta_tester_extras-options' )
		) {
			return;
		}

		if ( isset( $post_data['option_page'] )
			&& 'wp_beta_tester_extras' === $post_data['option_page']
		) {
			$options          = isset( $post_data['wp-beta-tester'] )
				? $post_data['wp-beta-tester']
				: array();
			$options          = WPBT_Settings::sanitize( $options );
			$filtered_options = array_filter( self::$options, array( $this, 'get_unchecked_options' ) );
			$options          = array_merge( $filtered_options, $options );
			update_site_option( 'wp_beta_tester', (array) $options );
			add_filter( 'wp_beta_tester_save_redirect', array( $this, 'save_redirect_page' ) );
		}
	}

	/**
	 * Filter saved setting to remove unchecked checkboxes.
	 *
	 * @param  array $checked Options.
	 * @return bool
	 */
	private function get_unchecked_options( $checked ) {
		return '1' !== $checked;
	}

	/**
	 * Redirect page/tab after saving options.
	 *
	 * @param  mixed $option_page Settings page.
	 * @return array
	 */
	public function save_redirect_page( $option_page ) {
		return array_merge( $option_page, array( 'wp_beta_tester_extras' ) );
	}

	/**
	 * Create core settings page.
	 *
	 * @param  array  $tab    Settings tab.
	 * @param  string $action Form action.
	 * @return void
	 */
	public function add_admin_page( $tab, $action ) {
		?>
		<div>
		<?php if ( 'wp_beta_tester_extras' === $tab ) : ?>
			<form method="post" action="<?php echo esc_attr( $action ); ?>">
				<?php settings_fields( 'wp_beta_tester_extras' ); ?>
				<?php do_settings_sections( 'wp_beta_tester_extras' ); ?>
				<?php submit_button(); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Skip successful autoupdate emails.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function skip_autoupdate_email() {
		if ( ! isset( self::$options['skip_autoupdate_email'] ) ) {
			return;
		}
		// Disable update emails on success.
		add_filter(
			'auto_core_update_send_email',
			static function ( $send, $type ) {
				$send = 'success' === $type ? false : $send;

				return $send;
			},
			10,
			2
		);
	}

	/**
	 * Skip bundled plugins and themes on Core Upgrade.
	 *
	 * @return void
	 */
	public function skip_bundled_files_on_upgrade() {
		if ( ! isset( self::$options['skip_bundled_files_on_upgrade'] ) ) {
			return;
		}

		if ( ! defined( 'CORE_UPGRADE_SKIP_NEW_BUNDLED' ) ) {
			define( 'CORE_UPGRADE_SKIP_NEW_BUNDLED', true );
		}
	}
}
