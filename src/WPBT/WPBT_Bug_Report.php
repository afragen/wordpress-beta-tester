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
 * WPBT_Bug_Report
 */
class WPBT_Bug_Report {
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
	 * Holds the operating system's name.
	 *
	 * @var string
	 */
	protected static $os;

	/**
	 * Holds the server's name.
	 *
	 * @var string
	 */
	protected static $server;

	/**
	 * Holds the browser's name and version.
	 *
	 * @var string
	 */
	protected static $browser;

	/**
	 * Holds the active theme's name.
	 *
	 * @var string
	 */
	protected static $theme;

	/**
	 * Holds a list of active plugins.
	 *
	 * @var string
	 */
	protected static $plugins;

	/**
	 * Holds a list of mu-plugins.
	 *
	 * @var string
	 */
	protected static $muplugins;

	/**
	 * Holds the string for unknown values.
	 *
	 * @var string
	 */
	protected static $unknown;

	/**
	 * Holds the string for no activated plugins.
	 *
	 * @var string
	 */
	protected static $none_activated;

	/**
	 * Constructor.
	 *
	 * @param  WP_Beta_Tester $wp_beta_tester Instance of class WP_Beta_Tester.
	 * @param  array          $options        Site options.
	 * @return void
	 */
	public function __construct( WP_Beta_Tester $wp_beta_tester, $options ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->wp_beta_tester = $wp_beta_tester;
		self::$options        = $options;
		self::$unknown        = __( 'Could not determine', 'wordpress-beta-tester' );
		self::$none_activated = __( 'None activated', 'wordpress-beta-tester' );
	}

	/**
	 * Load hooks.
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'wp_beta_tester_add_admin_bar_menu', array( $this, 'add_admin_bar_menu' ) );
		add_action( 'wp_beta_tester_add_admin_page', array( $this, 'add_admin_page' ), 10, 2 );
		add_filter( 'wp_beta_tester_add_settings_tabs', array( $this, 'add_settings_tab' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['tab'] ) || 'wp_beta_tester_bug_report' !== $_GET['tab'] ) {
			return;
		}

		$version = get_file_data( $this->wp_beta_tester->file, array( 'Version' => 'Version' ) )['Version'];
		wp_register_script( 'wordpress-beta-tester-bug_report', '', array( 'jquery', 'clipboard' ), $version, true );
		wp_enqueue_script( 'wordpress-beta-tester-bug_report' );
		wp_add_inline_script(
			'wordpress-beta-tester-bug_report',
			'
				var bugReportClipboard = new ClipboardJS( "#wordpress-beta-tester-bug-reports button" );

				bugReportClipboard.on( "success", function( e ) {
					var success = jQuery( e.trigger ).next( ".success" );

					success.removeClass( "hidden" );

					setTimeout( function() {
						success.addClass( "hidden" );
					}, 3000 );
				} );
			'
		);
	}

	/**
	 * Set environment data.
	 *
	 * @return void
	 */
	private function set_environment_data() {
		$this->set_os();
		$this->set_server();
		$this->set_browser();
		$this->set_theme();
		$this->set_mu_plugins();
		$this->set_plugins();
	}

	/**
	 * Set the operating system's name.
	 *
	 * @return void
	 */
	private function set_os() {
		self::$os = self::$unknown;

		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return;
		}

		$agent   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		$os_list = array(
			'/windows nt 10/i'      => 'Windows 10',
			'/windows nt 6.3/i'     => 'Windows 8.1',
			'/windows nt 6.2/i'     => 'Windows 8',
			'/windows nt 6.1/i'     => 'Windows 7',
			'/windows nt 6.0/i'     => 'Windows Vista',
			'/windows nt 5.2/i'     => 'Windows Server 2003/XP x64',
			'/windows nt 5.1/i'     => 'Windows XP',
			'/windows xp/i'         => 'Windows XP',
			'/windows nt 5.0/i'     => 'Windows 2000',
			'/windows me/i'         => 'Windows ME',
			'/win98/i'              => 'Windows 98',
			'/win95/i'              => 'Windows 95',
			'/win16/i'              => 'Windows 3.11',
			'/macintosh|mac os x/i' => 'macOS',
			'/mac_powerpc/i'        => 'Mac OS 9',
			'/linux/i'              => 'Linux',
			'/ubuntu/i'             => 'Ubuntu',
			'/iphone/i'             => 'iPhone',
			'/ipod/i'               => 'iPod',
			'/ipad/i'               => 'iPad',
			'/android/i'            => 'Android',
			'/blackberry/i'         => 'BlackBerry',
			'/webos/i'              => 'Mobile',
		);

		foreach ( $os_list as $regex => $value ) {
			if ( preg_match( $regex, $agent ) ) {
				self::$os = $value;
			}
		}

		return self::$os;
	}

	/**
	 * Set the server's name.
	 *
	 * @return void
	 */
	private function set_server() {
		global $is_apache, $is_IIS, $is_iis7, $is_nginx;

		self::$server = self::$unknown;

		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return;
		}

		$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		$servers  = array(
			'Apache' => $is_apache,
			'NGINX'  => $is_nginx,
			'IIS'    => $is_IIS,
			'IIS7'   => $is_iis7,
		);
		$filtered = array_filter( $servers );

		if ( empty( $filtered ) ) {
			return;
		}

		$server       = array_keys( $filtered );
		self::$server = end( $server ) . ' (' . PHP_OS . ')';

		// Try to get the server version.
		preg_match( '/\/([0-9\.\-]+)/', $software, $version );

		self::$server .= $version ? ' ' . $version[1] : '';
	}

	/**
	 * Set the browser's name and version based on the user agent.
	 *
	 * @return void
	 */
	private function set_browser() {
		global $is_lynx, $is_gecko, $is_opera, $is_NS4, $is_safari, $is_chrome, $is_IE, $is_edge;

		self::$browser = self::$unknown;

		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return;
		}

		$agent    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		$browsers = array(
			'Lynx'              => $is_lynx,
			'Gecko'             => $is_gecko,
			'Opera'             => $is_opera,
			'Netscape 4'        => $is_NS4,
			'Safari'            => $is_safari,
			'Internet Explorer' => $is_IE,
			'Edge'              => $is_edge,
			'Chrome'            => $is_chrome,
			'Firefox'           => false !== stripos( $agent, 'Firefox' ),
		);
		$filtered = array_filter( $browsers );

		if ( empty( $filtered ) ) {
			return;
		}

		$browser       = array_keys( $filtered );
		self::$browser = end( $browser );

		// Try to get the browser version.
		if ( 'Safari' === self::$browser ) {
			$regex = '/Version\/([0-9\.\-]+)/';
		} elseif ( 'Edge' === self::$browser ) {
			$regex = '/Edg\/([0-9\.\-]+)/';
		} else {
			$regex = '/' . self::$browser . '\/([0-9\.\-]+)/';
		}

		preg_match( $regex, $agent, $version );

		self::$browser .= $version ? ' ' . $version[1] : '';
		self::$browser .= wp_is_mobile() ? ' (' . __( 'Mobile', 'wordpress-beta-tester' ) . ')' : '';
	}

	/**
	 * Set the active theme's name.
	 *
	 * @return void
	 */
	private function set_theme() {
		self::$theme = self::$unknown;

		$theme = wp_get_theme();

		if ( ! $theme->exists() ) {
			return;
		}

		self::$theme = $theme->name . ' ' . $theme->version;
	}

	/**
	 * Set the list of active plugins.
	 *
	 * @return void
	 */
	private function set_plugins() {
		self::$plugins          = self::$none_activated;
		$plugin_files           = get_option( 'active_plugins' );
		$network_active_plugins = get_site_option( 'active_sitewide_plugins' );
		if ( $network_active_plugins ) {
			$plugin_files = array_unique( array_merge( $plugin_files, array_keys( $network_active_plugins ) ) );
		}

		if ( ! $plugin_files ) {
			return;
		}

		foreach ( $plugin_files as $k => &$plugin ) {
			$path    = trailingslashit( WP_PLUGIN_DIR ) . $plugin;
			$data    = get_plugin_data( $path );
			$name    = $data['Name'];
			$version = $data['Version'];

			$plugin = "&nbsp;&nbsp;* $name $version";
		}
		unset( $plugin );
		natcasesort( $plugin_files );

		self::$plugins = "\n" . implode( "\n", $plugin_files );
	}

	/**
	 * Set the list of mu-plugins.
	 *
	 * @return void
	 */
	private function set_mu_plugins() {
		self::$muplugins = self::$none_activated;
		$plugin_files    = get_mu_plugins();

		if ( ! $plugin_files ) {
			return;
		}

		foreach ( $plugin_files as $k => &$plugin ) {
			$path    = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins/' . $k;
			$data    = get_plugin_data( $path );
			$name    = ! empty( $data['Name'] ) ? $data['Name'] : $k;
			$version = ! empty( $data['Version'] ) ? $data['Version'] : '';

			$plugin = "&nbsp;&nbsp;* $name $version";
		}
		unset( $plugin );
		natcasesort( $plugin_files );

		self::$muplugins = "\n" . implode( "\n", $plugin_files );
	}

	/**
	 * Add admin bar menu.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar object.
	 * @return void
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( is_multisite() && ! is_super_admin() ) {
			return;
		}
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'wp-beta-tester-report-a-bug',
				'title' => __( 'Report a Bug', 'wordpress-beta-tester' ),
				'href'  => add_query_arg(
					array(
						'page' => 'wp-beta-tester',
						'tab'  => 'wp_beta_tester_bug_report',
					),
					is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' )
				),
				'meta'  => array( 'title' => __( 'Discovered a bug? Report it now!', 'wordpress-beta-tester' ) ),
			)
		);
	}

	/**
	 * Add core settings page.
	 *
	 * @param  array  $tab    Settings tab.
	 * @param  string $action Form action.
	 * @return void
	 */
	public function add_admin_page( $tab, $action ) {
		?>
		<div>
		<?php if ( 'wp_beta_tester_bug_report' === $tab ) : ?>
			<?php $this->set_environment_data(); ?>
			<form method="post" action="<?php echo esc_attr( $action ); ?>">
				<?php settings_fields( 'wp_beta_tester_bug_report' ); ?>

				<h2><?php esc_html_e( 'Report a Bug', 'wordpress-beta-tester' ); ?></h2>
				<?php $this->print_tab_introduction(); ?>

				<div id="wordpress-beta-tester-bug-reports" style="display: flex; flex-wrap: wrap; gap: 1rem;">
					<?php
						$this->print_bug_report_template(
							__( 'Trac', 'wordpress-beta-tester' ),
							'https://core.trac.wordpress.org/newticket',
							'wiki'
						);

						$this->print_bug_report_template(
							__( 'GitHub (Gutenberg)', 'wordpress-beta-tester' ),
							'https://github.com/WordPress/gutenberg/issues/new/choose',
							'markdown'
						);
					?>
				</div>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add class settings tab.
	 *
	 * @param  array $tabs Settings tabs.
	 * @return array
	 */
	public function add_settings_tab( $tabs ) {
		return array_merge( $tabs, array( 'wp_beta_tester_bug_report' => esc_html__( 'Report a Bug', 'wordpress-beta-tester' ) ) );
	}

	/**
	 * Print the tab's introduction.
	 *
	 * @return void
	 */
	public function print_tab_introduction() {
		$introduction  = '<p>' . __( 'This area provides bug report templates for pasting into Trac or GitHub.', 'wordpress-beta-tester' ) . '</p>';
		$introduction .= '<p>' . __( 'After pasting a template into Trac or GitHub, complete the <strong>Steps to Reproduce</strong>, <strong>Expected Results</strong> and <strong>Actual Results</strong> sections.', 'wordpress-beta-tester' ) . '</p>';
		echo wp_kses_post( $introduction );
	}

	/**
	 * Print a bug report template.
	 *
	 * @param string $title  The title of the bug report template.
	 * @param string $url    The URL to file a bug report.
	 * @param string $format The format to use. "wiki" or "markdown".
	 * @return void
	 */
	public function print_bug_report_template( $title, $url, $format ) {
			$test_report = $this->get_bug_report_template( $format );
		?>
		<div style="width: min( 100vw, 520px - .5rem); padding-bottom: 2rem;">
			<h2><?php echo esc_html( $title ); ?></h2>
			<div style="display: flex; align-items: center; gap: 1rem;">
				<a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank"><?php esc_html_e( 'File a report', 'wordpress-beta-tester' ); ?></a>
				<div style="display: flex; align-items: center; gap: .25rem;">
					<button type="button" class="button button-small" data-clipboard-text="<?php echo esc_attr( $test_report ); ?>"><?php esc_html_e( 'Copy to clipboard', 'wordpress-beta-tester' ); ?></button>
					<span class="success hidden" style="color: #008a20;" aria-hidden="true"><?php esc_html_e( 'Copied!', 'wordpress-beta-tester' ); ?></span>
				</div>
			</div>
			<?php echo wp_kses_post( '<div class="card" style="margin-top: 1rem;">' . nl2br( $this->get_bug_report_template( $format ) ) . '</div>' ); ?>
		</div>
		<?php
	}

	/**
	 * Generate a test report template.
	 *
	 * @param string $format The format to use. "wiki" or "markdown".
	 * @return string
	 */
	public function get_bug_report_template( $format ) {
		global $wp_version;

		$environment = array(
			'- OS: ' . self::$os,
			'- Server: ' . self::$server,
			'- PHP: ' . phpversion(),
			'- WordPress: ' . $wp_version,
			'- Browser: ' . self::$browser,
			'- Theme: ' . self::$theme,
			'- MU-Plugins: ' . self::$muplugins,
			'- Plugins: ' . self::$plugins,
		);

		$environment = implode( "\n", $environment );

		$heading   = 'wiki' === $format ? '===' : '###';
		$last_item = 'wiki' === $format ? 'x' : '2';
		$report    = <<<EOD
		$heading Bug Report
		Describe the bug.

		$heading Environment
		$environment

		$heading Steps to Reproduce
		1.&nbsp;
		$last_item. 🐞 Bug occurs.

		$heading Expected Results
		1.&nbsp; ✅ What should happen.

		$heading Actual Results
		1.&nbsp; ❌ What actually happened.
		EOD;

		return $report;
	}
}
