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
 * WPBT_Plugins
 *
 * Adds the ability to update installed plugins from their pre-release
 * (beta/RC) versions published on WordPress.org. See issue #6.
 *
 * Approach: many plugin authors tag pre-releases in their dot-org SVN
 * `tags/` directory (e.g. `9.9.0-beta.1`, `9.9.0-rc.1`). The 1.1 plugins API
 * exposes all tags via the `versions` field when requested, and
 * `downloads.wordpress.org` serves any tagged version, stable or not.
 *
 * UX: an "Update Version" dropdown on the Plugins list table decides which
 * version each installed dot-org plugin is offered.
 *
 * - `latest` (the default) offers the newest tag of any kind, pre-releases
 *   included, whenever it beats what WordPress.org itself offers.
 * - `stable` ("Latest Stable") opts a plugin out; WordPress is left alone and
 *   nothing is injected.
 * - A pinned version tag redirects the offer to that exact version, upwards or
 *   downwards. Once installed the pin holds: newer releases are withheld until
 *   the user picks something else, which is what makes a rollback stick.
 *
 * Auto-updates never consume any of these offers; see
 * `disable_autoupdate_for_offers()`.
 */
class WPBT_Plugins {
	/**
	 * Site transient prefix for cached dot-org version maps.
	 *
	 * @var string
	 */
	const VERSIONS_TRANSIENT_PREFIX = 'wpbt_plugin_versions_';

	/**
	 * Site transient prefix for cached per-tag changelogs.
	 *
	 * @var string
	 */
	const CHANGELOG_TRANSIENT_PREFIX = 'wpbt_prerelease_changelog_';

	/**
	 * Marker set on offers built by this class, read back by the
	 * `auto_update_plugin` filter.
	 *
	 * @var string
	 */
	const OFFER_MARKER = 'wpbt_offer';

	/**
	 * Package host for plugins hosted on WordPress.org.
	 *
	 * @var string
	 */
	const DOTORG_HOST = 'downloads.wordpress.org';

	/**
	 * Follow WordPress.org's own stable releases.
	 *
	 * @var string
	 */
	const VERSION_STABLE = 'stable';

	/**
	 * Follow the newest tag of any kind, pre-releases included.
	 *
	 * The default: this is a beta testing plugin, so a site that installs it is
	 * asking to see pre-releases. Nothing installs unattended regardless; see
	 * `disable_autoupdate_for_offers()`.
	 *
	 * @var string
	 */
	const VERSION_LATEST = 'latest';


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
		self::$options        = $options;
		$this->wp_beta_tester = $wp_beta_tester;
	}

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public function init() {
		$this->migrate_options();
		$this->load_hooks();
	}

	/**
	 * Bring older option shapes forward to `plugin_update_versions`.
	 *
	 * `plugin_betas` was a per-plugin opt-in flag; `selected_plugin_versions`
	 * was a one-shot version selection, which is what a pin now is.
	 *
	 * @return void
	 */
	private function migrate_options() {
		$options = (array) self::$options;
		$changed = false;

		if ( isset( $options['plugin_betas'] ) ) {
			unset( $options['plugin_betas'] );
			$changed = true;
		}

		if ( isset( $options['selected_plugin_versions'] ) ) {
			$update_versions = isset( $options['plugin_update_versions'] ) ? (array) $options['plugin_update_versions'] : array();

			foreach ( (array) $options['selected_plugin_versions'] as $plugin_file => $version ) {
				if ( ! isset( $update_versions[ $plugin_file ] ) && is_string( $version ) && '' !== $version ) {
					$update_versions[ $plugin_file ] = $version;
				}
			}

			unset( $options['selected_plugin_versions'] );
			$options['plugin_update_versions'] = $update_versions;
			$changed                           = true;
		}

		if ( $changed ) {
			update_site_option( 'wp_beta_tester', $options );
			self::$options = $options;
		}
	}

	/**
	 * Load hooks.
	 *
	 * @return void
	 */
	public function load_hooks() {
		// Update Version column on the Plugins list table.
		add_filter( 'manage_plugins_columns', array( $this, 'add_version_column' ) );
		add_filter( 'manage_plugins-network_columns', array( $this, 'add_version_column' ) );
		add_action( 'manage_plugins_custom_column', array( $this, 'render_version_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Forget pins belonging to plugins that have since been deleted.
		add_action( 'load-plugins.php', array( $this, 'prune_stale_pins' ) );

		add_action( 'wp_ajax_wpbt_plugin_versions', array( $this, 'ajax_plugin_versions' ) );
		add_action( 'wp_ajax_wpbt_set_update_version', array( $this, 'ajax_set_update_version' ) );

		// Inject pre-release update offers after core populates the transient.
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_prerelease_updates' ), 20 );

		// Add pre-release notice to the 'View details' modal for offered plugins.
		// 'plugins_api_result' fires after the API response; 'plugins_api' is a
		// short-circuit filter that fires before the request, with a false result.
		add_filter( 'plugins_api_result', array( $this, 'filter_plugin_information' ), 10, 3 );

		// Never let an unattended auto-update consume one of our offers.
		add_filter( 'auto_update_plugin', array( $this, 'disable_autoupdate_for_offers' ), 10, 2 );

		// Clear cached version data when upgrades complete.
		add_action( 'upgrader_process_complete', array( $this, 'clear_version_cache' ), 10, 0 );

		// Clear cached version data when the user explicitly checks for updates.
		add_action( 'load-update-core.php', array( $this, 'clear_version_cache_on_force_check' ) );
	}

	/**
	 * Get the per-plugin update version settings.
	 *
	 * Plugins absent from the map follow the default, `latest`.
	 *
	 * @return array Map of plugin file => `latest` or a pinned version tag.
	 */
	public static function get_update_versions() {
		$update_versions = isset( self::$options['plugin_update_versions'] ) ? self::$options['plugin_update_versions'] : array();

		return is_array( $update_versions ) ? $update_versions : array();
	}

	/**
	 * Get one plugin's update version setting.
	 *
	 * @param  string $plugin_file Plugin file path.
	 * @return string `stable`, `latest`, or a pinned version tag.
	 */
	public static function get_update_version( $plugin_file ) {
		$update_versions = self::get_update_versions();

		return isset( $update_versions[ $plugin_file ] ) ? $update_versions[ $plugin_file ] : self::VERSION_LATEST;
	}

	/**
	 * Store one plugin's update version setting.
	 *
	 * The default is stored as an absence, so a site that never touches this
	 * feature keeps an empty map.
	 *
	 * @param  string $plugin_file Plugin file path.
	 * @param  string $update_version     `stable`, `latest`, or a version tag to pin.
	 * @return void
	 */
	public static function set_update_version( $plugin_file, $update_version ) {
		$update_versions = self::get_update_versions();

		if ( self::VERSION_LATEST === $update_version ) {
			unset( $update_versions[ $plugin_file ] );
		} else {
			$update_versions[ $plugin_file ] = $update_version;
		}

		$options                           = (array) self::$options;
		$options['plugin_update_versions'] = $update_versions;
		update_site_option( 'wp_beta_tester', $options );
		self::$options = $options;
	}

	/**
	 * Drop pins belonging to plugins that are no longer installed.
	 *
	 * A pin on an installed plugin is left alone however old it is: it is a lock,
	 * and only the user clears it. This just stops pins for deleted plugins
	 * accumulating in the option forever.
	 *
	 * Runs on admin page loads, never inside the update transient filter, which
	 * must not write to the database.
	 *
	 * @return void
	 */
	public function prune_stale_pins() {
		$update_versions = self::get_update_versions();
		if ( empty( $update_versions ) ) {
			return;
		}

		$installed = $this->get_installed_plugins();
		$changed   = false;

		foreach ( $update_versions as $plugin_file => $update_version ) {
			if ( self::VERSION_LATEST === $update_version ) {
				continue;
			}

			if ( ! isset( $installed[ $plugin_file ] ) ) {
				unset( $update_versions[ $plugin_file ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			$options                           = (array) self::$options;
			$options['plugin_update_versions'] = $update_versions;
			update_site_option( 'wp_beta_tester', $options );
			self::$options = $options;
		}
	}

	/**
	 * Add the Update Version column to the Plugins list table.
	 *
	 * @param  array $columns Existing columns.
	 * @return array
	 */
	public function add_version_column( $columns ) {
		$columns['wpbt-update-version'] = esc_html__( 'Update Version', 'wordpress-beta-tester' );

		return $columns;
	}

	/**
	 * Render the Update Version column for one plugin.
	 *
	 * @param  string $column_name Column being rendered.
	 * @param  string $plugin_file Plugin file path.
	 * @return void
	 */
	public function render_version_column( $column_name, $plugin_file ) {
		if ( 'wpbt-update-version' !== $column_name ) {
			return;
		}

		$dotorg = self::current_dotorg_plugins();
		if ( ! isset( $dotorg[ $plugin_file ] ) ) {
			echo '<span class="description">' . esc_html__( 'Not hosted on WordPress.org', 'wordpress-beta-tester' ) . '</span>';

			return;
		}

		$update_version = self::get_update_version( $plugin_file );

		if ( ! current_user_can( 'update_plugins' ) ) {
			echo esc_html( self::version_label( $update_version ) );

			return;
		}

		$field_id = 'wpbt-update-version-' . md5( $plugin_file );
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>">
			<?php esc_html_e( 'Update version', 'wordpress-beta-tester' ); ?>
		</label>
		<select
			id="<?php echo esc_attr( $field_id ); ?>"
			class="wpbt-update-version-select"
			data-plugin="<?php echo esc_attr( $plugin_file ); ?>"
		>
			<option value="<?php echo esc_attr( self::VERSION_LATEST ); ?>" <?php selected( self::VERSION_LATEST, $update_version ); ?>>
				<?php esc_html_e( 'Latest', 'wordpress-beta-tester' ); ?>
			</option>
			<option value="<?php echo esc_attr( self::VERSION_STABLE ); ?>" <?php selected( self::VERSION_STABLE, $update_version ); ?>>
				<?php esc_html_e( 'Latest Stable', 'wordpress-beta-tester' ); ?>
			</option>
			<?php if ( self::is_pinned( $update_version ) ) : ?>
				<option value="<?php echo esc_attr( $update_version ); ?>" selected="selected">
					<?php
					/* translators: %s: pinned version number */
					printf( esc_html__( 'Pinned to %s', 'wordpress-beta-tester' ), esc_html( $update_version ) );
					?>
				</option>
			<?php endif; ?>
		</select>
		<span class="wpbt-update-version-status" aria-live="polite"></span>
		<?php
	}

	/**
	 * Human-readable name for an update version setting.
	 *
	 * @param  string $update_version Stored setting.
	 * @return string
	 */
	private static function version_label( $update_version ) {
		if ( self::VERSION_LATEST === $update_version ) {
			return __( 'Latest', 'wordpress-beta-tester' );
		}

		if ( self::VERSION_STABLE === $update_version ) {
			return __( 'Latest Stable', 'wordpress-beta-tester' );
		}

		/* translators: %s: pinned version number */
		return sprintf( __( 'Pinned to %s', 'wordpress-beta-tester' ), $update_version );
	}

	/**
	 * Whether a setting is a pinned version rather than Latest or Latest Stable.
	 *
	 * @param  string $update_version Stored setting.
	 * @return bool
	 */
	private static function is_pinned( $update_version ) {
		return self::VERSION_STABLE !== $update_version && self::VERSION_LATEST !== $update_version;
	}

	/**
	 * Enqueue the script backing the Update Version column.
	 *
	 * @param  string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$relative = 'assets/js/update-versions.js';
		$path     = dirname( dirname( __DIR__ ) ) . '/' . $relative;

		wp_enqueue_script(
			'wpbt-update-versions',
			plugins_url( $relative, dirname( dirname( __DIR__ ) ) . '/wp-beta-tester.php' ),
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : false,
			true
		);

		wp_localize_script(
			'wpbt-update-versions',
			'wpbtUpdateVersions',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpbt-update-versions' ),
				'strings' => array(
					'loading'         => __( 'Loading versions…', 'wordpress-beta-tester' ),
					'saving'          => __( 'Saving…', 'wordpress-beta-tester' ),
					'saved'           => __( 'Saved.', 'wordpress-beta-tester' ),
					'failed'          => __( 'Failed. Please try again.', 'wordpress-beta-tester' ),
					'specificVersion' => __( 'Specific version', 'wordpress-beta-tester' ),
					'prerelease'      => __( 'pre-release', 'wordpress-beta-tester' ),
				),
			)
		);
	}

	/**
	 * Ajax: return a plugin's tagged versions, newest first.
	 *
	 * Only the requested plugin is fetched, so opening one dropdown costs a
	 * single API call rather than one per installed plugin.
	 *
	 * @return void
	 */
	public function ajax_plugin_versions() {
		$plugin_file = $this->validate_ajax_request();
		$dotorg      = self::current_dotorg_plugins();

		$data = self::get_versions( $dotorg[ $plugin_file ]->slug );
		if ( null === $data ) {
			wp_send_json_error( array( 'message' => __( 'Could not load the version list from WordPress.org.', 'wordpress-beta-tester' ) ) );
		}

		$versions = array();
		foreach ( self::list_all_versions( $data['versions'] ) as $tag ) {
			$versions[] = array(
				'value'      => $tag,
				'label'      => $tag,
				'prerelease' => WPBT_Version_Compare::is_prerelease( $tag ),
			);
		}

		wp_send_json_success( array( 'versions' => $versions ) );
	}

	/**
	 * Ajax: store a plugin's update version setting.
	 *
	 * @return void
	 */
	public function ajax_set_update_version() {
		// Nonce and capability are verified in validate_ajax_request().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$plugin_file = $this->validate_ajax_request();
		$dotorg      = self::current_dotorg_plugins();

		$update_version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( self::is_pinned( $update_version ) ) {
			// A pinned tag must exist in the plugin's dot-org version list.
			$data = self::get_versions( $dotorg[ $plugin_file ]->slug );
			if ( null === $data || ! isset( $data['versions'][ $update_version ] ) ) {
				wp_send_json_error( array( 'message' => __( 'That version is not available.', 'wordpress-beta-tester' ) ) );
			}
		}

		self::set_update_version( $plugin_file, $update_version );

		// No cache to bust: offers are computed in the read filter, so the next
		// read of the update transient already reflects the new setting.
		wp_send_json_success( array( 'label' => self::version_label( $update_version ) ) );
	}

	/**
	 * Shared validation for both Ajax endpoints.
	 *
	 * Sends a JSON error and exits unless the request is a valid, authorised
	 * one naming an installed WordPress.org plugin.
	 *
	 * @return string The validated plugin file path.
	 */
	private function validate_ajax_request() {
		check_ajax_referer( 'wpbt-update-versions', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to change plugin versions.', 'wordpress-beta-tester' ) ), 403 );
		}

		// The nonce is verified by check_ajax_referer() above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$plugin_file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$installed   = $this->get_installed_plugins();
		$dotorg      = self::current_dotorg_plugins();

		if ( ! isset( $dotorg[ $plugin_file ], $installed[ $plugin_file ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'wordpress-beta-tester' ) ), 400 );
		}

		return $plugin_file;
	}

	/**
	 * The dot-org plugin map for this request, resolved once.
	 *
	 * @return array Map of plugin file => core's dot-org update object.
	 */
	private static function current_dotorg_plugins() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = self::get_dotorg_plugins( get_site_transient( 'update_plugins' ) );
		}

		return $cache;
	}

	/**
	 * Get all installed plugins (single site or network).
	 *
	 * @return array Array of plugin file => plugin data, as returned by get_plugins().
	 */
	private function get_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		// All plugins (active and inactive) are eligible on both single and multisite.
		return get_plugins();
	}

	/**
	 * Clear cached version data when the user clicks "Check Again" on update-core.php.
	 *
	 * Core handles `?force-check=1` by deleting its own update transients; this
	 * keeps our pre-release data in step so an explicit check is never served
	 * from a stale cache.
	 *
	 * @return void
	 */
	public function clear_version_cache_on_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['force-check'] ) ) {
			$this->clear_version_cache();
		}
	}

	/**
	 * Clear cached version data.
	 *
	 * Only the version maps are cleared. Per-tag changelogs are keyed by an
	 * immutable (slug, tag) pair, so they never go stale and simply expire.
	 *
	 * @return void
	 */
	public function clear_version_cache() {
		$slugs = array();

		// The directory name is the slug for virtually every dot-org plugin, and
		// unlike the transient it is still available right after a force-check
		// has flushed it. A wrong guess only deletes a key that does not exist.
		foreach ( array_keys( $this->get_installed_plugins() ) as $plugin_file ) {
			if ( false !== strpos( $plugin_file, '/' ) ) {
				$slugs[] = dirname( $plugin_file );
			}
		}

		// Authoritative slugs, covering the rare renamed plugin directory.
		foreach ( self::get_dotorg_plugins( get_site_transient( 'update_plugins' ) ) as $item ) {
			$slugs[] = $item->slug;
		}

		foreach ( array_unique( $slugs ) as $slug ) {
			delete_site_transient( self::VERSIONS_TRANSIENT_PREFIX . sanitize_key( $slug ) );
		}
	}

	/**
	 * Get the changelog from a pre-release tag's readme.txt, cached one level up.
	 *
	 * Wrapper around `fetch_prerelease_changelog()`; the cache lives here so
	 * the fetch itself stays directly callable without caching.
	 *
	 * @param  string $slug Plugin slug.
	 * @param  string $tag  Tag, e.g. `11.2.0-beta.1`.
	 * @return string|null Formatted changelog HTML, or null when unavailable.
	 */
	public static function get_prerelease_changelog( $slug, $tag ) {
		// md5 avoids collisions from sanitize_key() stripping dots in tags.
		$cache_key = self::CHANGELOG_TRANSIENT_PREFIX . md5( $slug . '|' . $tag );
		$cached    = get_site_transient( $cache_key );
		if ( is_string( $cached ) ) {
			// A cached failure is stored as an empty string.
			return '' === $cached ? null : $cached;
		}

		/**
		 * Filters the cache lifetime for pre-release changelog data.
		 *
		 * @param int    $ttl       Lifetime in seconds. Default 1 hour.
		 * @param string $cache_key The transient key.
		 */
		$ttl = (int) apply_filters( 'wpbt_prerelease_changelog_cache_ttl', HOUR_IN_SECONDS, $cache_key );

		$html = self::fetch_prerelease_changelog( $slug, $tag );
		if ( null === $html ) {
			// Most tags have no readme of their own; do not refetch on every view.
			set_site_transient( $cache_key, '', $ttl );

			return null;
		}

		set_site_transient( $cache_key, $html, $ttl );

		return $html;
	}

	/**
	 * Fetch the changelog from a tag's own readme.txt on dot-org SVN.
	 * No caching.
	 *
	 * The plugins API exposes sections from the stable/trunk readme only, so the
	 * changelog for a selected version is fetched from the tag's public SVN readme.
	 *
	 * @param  string $slug Plugin slug.
	 * @param  string $tag  Tag, e.g. `11.2.0-beta.1`.
	 * @return string|null Formatted changelog HTML, or null when unavailable.
	 */
	public static function fetch_prerelease_changelog( $slug, $tag ) {
		$response = wp_remote_get(
			sprintf( 'https://plugins.svn.wordpress.org/%1$s/tags/%2$s/readme.txt', rawurlencode( $slug ), rawurlencode( $tag ) ),
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$readme    = (string) wp_remote_retrieve_body( $response );
		$changelog = '';
		if ( preg_match( '/==\s*Changelog\s*==\s*\n(.*?)(?:\n==|$)/is', $readme, $m ) ) {
			$changelog = trim( $m[1] );
		}

		if ( '' === $changelog ) {
			return null;
		}

		return self::format_readme_changelog( $changelog );
	}

	/**
	 * Format a readme.txt changelog excerpt as HTML.
	 *
	 * `= version =` headings become h4 elements; other lines become paragraphs.
	 *
	 * @param  string $changelog Raw changelog text.
	 * @return string
	 */
	public static function format_readme_changelog( $changelog ) {
		$lines = preg_split( '/\R/', (string) $changelog );
		$html  = '';
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $m ) ) {
				$html .= '<h4>' . esc_html( $m[1] ) . "</h4>\n";
				continue;
			}
			$html .= '<p>' . esc_html( $line ) . "</p>\n";
		}

		return $html;
	}

	/**
	 * Get the versions map for a plugin slug, cached one level up.
	 *
	 * Wrapper around `fetch_versions()`; the cache lives here so the fetch
	 * itself stays directly callable without caching.
	 *
	 * @param  string $slug Plugin slug.
	 * @return array{ version: string, versions: array }|null Array with stable `version` and `versions` map (tag => download URL), or null on failure.
	 */
	public static function get_versions( $slug ) {
		$cached = self::get_cached_versions( $slug );
		if ( false !== $cached ) {
			return $cached;
		}

		$cache_key = self::VERSIONS_TRANSIENT_PREFIX . sanitize_key( $slug );

		/**
		 * Filters the cache lifetime for plugin version data.
		 *
		 * @param int    $ttl       Lifetime in seconds. Default 1 hour.
		 * @param string $cache_key The transient key.
		 */
		$ttl = (int) apply_filters( 'wpbt_plugin_versions_cache_ttl', HOUR_IN_SECONDS, $cache_key );

		$data = self::fetch_versions( $slug );
		if ( null === $data ) {
			// Cache the failure too, so an API outage cannot turn every read of
			// the update transient into a fresh round of blocking requests.
			set_site_transient( $cache_key, array(), min( $ttl, 15 * MINUTE_IN_SECONDS ) );

			return null;
		}

		set_site_transient( $cache_key, $data, $ttl );

		return $data;
	}

	/**
	 * Read a plugin's cached versions map without contacting WordPress.org.
	 *
	 * Distinguishes three states so callers can budget their network use:
	 * an array of data, `null` for a cached failure, and `false` for a miss.
	 *
	 * @param  string $slug Plugin slug.
	 * @return array|null|false
	 */
	private static function get_cached_versions( $slug ) {
		$cached = get_site_transient( self::VERSIONS_TRANSIENT_PREFIX . sanitize_key( $slug ) );

		if ( ! is_array( $cached ) ) {
			return false;
		}

		// A cached failure is stored as an empty array.
		return empty( $cached ) ? null : $cached;
	}

	/**
	 * Fetch the versions map for a plugin slug. No caching.
	 *
	 * @param  string $slug Plugin slug.
	 * @return array{ version: string, versions: array }|null Array with stable `version` and `versions` map (tag => download URL), or null on failure.
	 */
	public static function fetch_versions( $slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'versions' => true,
					'sections' => false,
				),
			)
		);

		if ( is_wp_error( $info ) || empty( $info->versions ) || ! is_array( $info->versions ) ) {
			return null;
		}

		return array(
			'version'  => isset( $info->version ) ? $info->version : '',
			'versions' => $info->versions,
		);
	}

	/**
	 * List a plugin's tagged versions, newest first.
	 *
	 * @param  array $versions Map of tag => download URL from the plugins API.
	 * @return array Sorted tag names, newest first.
	 */
	public static function list_all_versions( $versions ) {
		if ( ! is_array( $versions ) || empty( $versions ) ) {
			return array();
		}

		$candidates = array();
		foreach ( array_keys( $versions ) as $tag ) {
			// Only offer parseable versions; skip exotic tags like `trunk`.
			if ( null !== WPBT_Version_Compare::parse( $tag ) ) {
				$candidates[] = $tag;
			}
		}

		usort( $candidates, array( 'WPBT_Version_Compare', 'compare' ) );

		return array_reverse( $candidates );
	}

	/**
	 * Pick the newest tag of any kind above the installed version.
	 *
	 * Pre-releases and stable releases compete on equal terms: this is the
	 * `latest` setting, so whichever tag sorts highest wins. When that turns out
	 * to be a stable release WordPress.org is already offering, the caller's
	 * comparison against the existing offer leaves core's own update in place.
	 *
	 * @param  array  $versions  Map of tag => download URL from the plugins API.
	 * @param  string $installed Currently installed version string.
	 * @return string|null Newest eligible tag, or null when none is newer.
	 */
	public static function pick_latest_version( $versions, $installed ) {
		$candidates = self::list_all_versions( $versions );

		// Sorted newest first, so the first one above installed is the newest.
		foreach ( $candidates as $tag ) {
			if ( WPBT_Version_Compare::compare( $tag, $installed ) > 0 ) {
				return $tag;
			}
		}

		return null;
	}

	/**
	 * Add a notice to the 'View details' modal for plugins we are making an offer for.
	 *
	 * The version offered here differs from dot-org's stable release. Core's modal
	 * fetches stable data, so prepend a notice describing the version actually
	 * being offered and swap in that tag's own changelog.
	 *
	 * @param  object|false|WP_Error $result Plugin information result.
	 * @param  string                $action Requested action.
	 * @param  object                $args   Request arguments, includes ->slug.
	 * @return object|false|WP_Error
	 */
	public function filter_plugin_information( $result, $action, $args ) {
		static $in_progress = false;

		if ( $in_progress
			|| 'plugin_information' !== $action
			|| ! is_object( $result )
			|| empty( $args->slug )
			|| ! isset( $result->sections['description'] )
		) {
			return $result;
		}

		// Reading the update transient runs inject_prerelease_updates(), which
		// calls plugins_api() and so re-enters this filter. Guard that one call.
		$in_progress = true;
		$offers      = self::current_offers();
		$in_progress = false;

		$offered     = '';
		$plugin_file = '';
		foreach ( $offers as $file => $offer ) {
			if ( $offer->slug === $args->slug ) {
				$offered     = $offer->new_version;
				$plugin_file = $file;
				break;
			}
		}
		if ( '' === $offered ) {
			return $result;
		}

		$pinned = self::is_pinned( self::get_update_version( $plugin_file ) );

		// An ordinary stable offer explains itself; say nothing.
		if ( ! $pinned && ! WPBT_Version_Compare::is_prerelease( $offered ) ) {
			return $result;
		}

		if ( $pinned ) {
			$message = sprintf(
				/* translators: 1: plugin name, 2: pinned version number */
				esc_html__( '%1$s is pinned to %2$s, so that is the version being offered. It will stay on that version until you change this in the Update Version column on the Plugins screen.', 'wordpress-beta-tester' ),
				esc_html( $result->name ),
				'<strong>' . esc_html( $offered ) . '</strong>'
			);
		} else {
			$message = sprintf(
				/* translators: 1: version number, 2: plugin name */
				esc_html__( '%1$s is a pre-release. It will never install on its own — set %2$s to “Latest Stable” in the Update Version column on the Plugins screen to stop being offered pre-releases.', 'wordpress-beta-tester' ),
				'<strong>' . esc_html( $offered ) . '</strong>',
				esc_html( $result->name )
			);
		}

		$notice = sprintf(
			'<div class="notice notice-info"><p><strong>%1$s:</strong> %2$s</p></div>',
			esc_html__( 'WordPress Beta Tester', 'wordpress-beta-tester' ),
			$message
		);

		// The modal header shows the stable version; make it show the offered one.
		$result->version = $offered;

		// The changelog section is the default modal view; show the notice there too.
		foreach ( array( 'description', 'changelog' ) as $section ) {
			if ( isset( $result->sections[ $section ] ) ) {
				$result->sections[ $section ] = wp_kses_post( $notice ) . $result->sections[ $section ];
			}
		}

		// Prefer the selected tag's own changelog from SVN; fall back to stable's.
		$selected_changelog = self::get_prerelease_changelog( $args->slug, $offered );
		if ( null !== $selected_changelog ) {
			$result->sections['changelog'] = wp_kses_post( $notice ) . $selected_changelog;
		}

		return $result;
	}

	/**
	 * Build an update offer by re-pointing core's own dot-org object at a tag.
	 *
	 * The `no_update`/`response` entry core stores for a WordPress.org plugin
	 * already carries `slug`, `url`, `icons`, `banners`, `requires`, `tested`
	 * and `requires_php`, so the offer is that object with the version and
	 * package swapped. This keeps the update row identical to a normal one and
	 * avoids a second, uncached `plugins_api()` request per plugin per page load.
	 *
	 * @param  object $base        Core's dot-org update object for this plugin.
	 * @param  string $plugin_file Plugin file path.
	 * @param  string $version     Offered version tag.
	 * @param  string $package     Download URL for the offered tag.
	 * @return object
	 */
	private static function make_update_offer( $base, $plugin_file, $version, $package ) {
		$offer = clone $base;

		$offer->plugin      = $plugin_file;
		$offer->new_version = $version;
		$offer->package     = $package;

		// Belt and braces: `autoupdate` only ever forces an update *on* in core,
		// so the real guard is disable_autoupdate_for_offers() via this marker.
		$offer->autoupdate           = false;
		$offer->{self::OFFER_MARKER} = true;

		// The stable release's upgrade notice does not describe this tag.
		unset( $offer->upgrade_notice );

		return $offer;
	}

	/**
	 * Prevent unattended auto-updates from installing our offers.
	 *
	 * `autoupdate => false` on the update object is *not* enough: core reads it
	 * as "force on" (`$update = ! empty( $item->autoupdate )`) and then still
	 * consults the site's `auto_update_plugins` option, so a plugin the user has
	 * auto-updates enabled for would silently install a pre-release overnight.
	 *
	 * A null `$update` means core is not deciding whether to run an update: it is
	 * asking whether auto-updates are *forced* one way or the other, to render the
	 * Plugins screen's Automatic Updates column. Answering false there would show
	 * a permanent "Auto-updates disabled" label in place of the user's own toggle,
	 * so leave that question alone — our refusal is per-offer and temporary, not a
	 * standing setting.
	 *
	 * @param  bool|null $update Whether to auto-update, or null when core is only
	 *                           asking whether the decision is forced.
	 * @param  object    $item   The update offer being considered.
	 * @return bool|null
	 */
	public function disable_autoupdate_for_offers( $update, $item ) {
		if ( null === $update || empty( $item->{self::OFFER_MARKER} ) ) {
			return $update;
		}

		return false;
	}

	/**
	 * Map the installed plugins that WordPress.org actually serves updates for.
	 *
	 * Core records one object per dot-org plugin in the update transient's
	 * `no_update`/`response` arrays, carrying the authoritative slug. Reading
	 * that is both cheaper and more accurate than guessing the slug from the
	 * plugin's directory name, and it keeps premium and self-hosted plugins —
	 * which have no dot-org tags — out of every code path below.
	 *
	 * @param  object $transient The `update_plugins` site transient.
	 * @return array Map of plugin file => core's dot-org update object.
	 */
	private static function get_dotorg_plugins( $transient ) {
		$plugins = array();

		foreach ( array( 'no_update', 'response' ) as $key ) {
			if ( ! isset( $transient->$key ) || ! is_array( $transient->$key ) ) {
				continue;
			}

			foreach ( $transient->$key as $plugin_file => $item ) {
				if ( ! is_object( $item ) || empty( $item->slug ) || empty( $item->package ) ) {
					continue;
				}

				// Third-party updaters also populate these arrays; only dot-org
				// packages have tags we can enumerate.
				if ( self::DOTORG_HOST !== wp_parse_url( $item->package, PHP_URL_HOST ) ) {
					continue;
				}

				$plugins[ $plugin_file ] = $item;
			}
		}

		return $plugins;
	}

	/**
	 * Inject plugin update offers into the update_plugins transient.
	 *
	 * Two kinds of offers, an explicit selection always winning:
	 * 1. Selections from the version selection table (may downgrade).
	 * 2. The newest pre-release of every installed dot-org plugin, offered as
	 *    a regular update when it beats what dot-org itself offers.
	 *
	 * Hooked on `site_transient_update_plugins`, after core populates it.
	 *
	 * @param  object $transient Site transient value.
	 * @return object
	 */
	public function inject_prerelease_updates( $transient ) {
		static $in_progress = false;

		// Nothing is cached yet. Leave it alone: fabricating an object here would
		// give it a fresh `last_checked` and stop core running its own check.
		if ( $in_progress || ! is_object( $transient ) ) {
			return $transient;
		}

		$dotorg = self::get_dotorg_plugins( $transient );
		if ( empty( $dotorg ) ) {
			return $transient;
		}

		$in_progress     = true;
		$installed       = $this->get_installed_plugins();
		$update_versions = self::get_update_versions();

		/**
		 * Filters how many uncached version lookups one read of the update
		 * transient may perform.
		 *
		 * `latest` is the default, so a site with many plugins would
		 * otherwise make one blocking request per plugin the first time the cache
		 * is cold. Capping it spreads that cost over successive reads: offers
		 * appear progressively instead of stalling a single page load.
		 *
		 * @param int $budget Maximum uncached lookups per read. Default 5.
		 */
		$budget = (int) apply_filters( 'wpbt_version_fetch_budget', 5 );

		foreach ( $dotorg as $plugin_file => $base ) {
			if ( ! isset( $installed[ $plugin_file ] ) ) {
				continue;
			}

			$update_version = isset( $update_versions[ $plugin_file ] ) ? $update_versions[ $plugin_file ] : self::VERSION_LATEST;
			if ( self::VERSION_STABLE === $update_version ) {
				continue;
			}

			$slug          = $base->slug;
			$installed_ver = $installed[ $plugin_file ]['Version'];

			// A pin overrides core's offer, in either direction, until it is met.
			// Once met it holds: any update WordPress.org is offering is demoted to
			// no_update, so the plugin stays put until the pin is changed. Without
			// this a rollback would be pointless — the version just rolled back from
			// would be offered again immediately.
			if ( self::VERSION_LATEST !== $update_version
				&& 0 === WPBT_Version_Compare::compare( $update_version, $installed_ver )
			) {
				if ( isset( $transient->response[ $plugin_file ] ) ) {
					$transient->no_update[ $plugin_file ] = $transient->response[ $plugin_file ];
					unset( $transient->response[ $plugin_file ] );
				}

				continue;
			}

			$data = self::get_cached_versions( $slug );
			if ( false === $data ) {
				if ( $budget < 1 ) {
					continue;
				}
				--$budget;
				$data = self::get_versions( $slug );
			}

			if ( null === $data ) {
				continue;
			}

			if ( self::VERSION_LATEST === $update_version ) {
				$offered = self::pick_latest_version( $data['versions'], $installed_ver );
				if ( null === $offered ) {
					continue;
				}

				// The newest tag is one WordPress.org already offers, or older than
				// what it offers. Either way, leave core's own update alone.
				if ( isset( $transient->response[ $plugin_file ] )
					&& WPBT_Version_Compare::compare( $offered, $transient->response[ $plugin_file ]->new_version ) <= 0
				) {
					continue;
				}
			} else {
				$offered = $update_version;
			}

			if ( ! isset( $data['versions'][ $offered ] ) ) {
				continue;
			}

			$transient->response[ $plugin_file ] = self::make_update_offer( $base, $plugin_file, $offered, $data['versions'][ $offered ] );
			unset( $transient->no_update[ $plugin_file ] );
		}

		$in_progress = false;

		return $transient;
	}

	/**
	 * The offers this plugin has injected into the current update transient.
	 *
	 * @return array Map of plugin file => offer object.
	 */
	private static function current_offers() {
		$transient = get_site_transient( 'update_plugins' );
		$offers    = array();

		if ( isset( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $plugin_file => $offer ) {
				if ( ! empty( $offer->{self::OFFER_MARKER} ) ) {
					$offers[ $plugin_file ] = $offer;
				}
			}
		}

		return $offers;
	}
}
