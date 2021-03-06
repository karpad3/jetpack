<?php
include_once( 'class.jetpack-admin-page.php' );

// Builds the landing page and its menu
class Jetpack_React_Page extends Jetpack_Admin_Page {

	protected $dont_show_if_not_active = false;

	protected $is_redirecting = false;

	function get_page_hook() {
		$title = _x( 'Jetpack', 'The menu item label', 'jetpack' );

		// Add the main admin Jetpack menu
		return add_menu_page( 'Jetpack', $title, 'jetpack_admin_page', 'jetpack', array( $this, 'render' ), 'div' );
	}

	function add_page_actions( $hook ) {
		/** This action is documented in class.jetpack.php */
		do_action( 'jetpack_admin_menu', $hook );

		// Place the Jetpack menu item on top and others in the order they appear
		add_filter( 'custom_menu_order',         '__return_true' );
		add_filter( 'menu_order',                array( $this, 'jetpack_menu_order' ) );

		if ( ! isset( $_GET['page'] ) || 'jetpack' !== $_GET['page'] || ! empty( $_GET['configure'] ) ) {
			return; // No need to handle the fallback redirection if we are not on the Jetpack page
		}

		// Adding a redirect meta tag for older WordPress versions
		if ( $this->is_wp_version_too_old() ) {
			$this->is_redirecting = true;
			add_action( 'admin_head', array( $this, 'add_fallback_head_meta' ) );
		}

		// Adding a redirect meta tag wrapped in noscript tags for all browsers in case they have JavaScript disabled
		add_action( 'admin_head', array( $this, 'add_noscript_head_meta' ) );

		// Adding a redirect tag wrapped in browser conditional comments
		add_action( 'admin_head', array( $this, 'add_legacy_browsers_head_script' ) );
	}

	/**
	 * Add Jetpack Dashboard sub-link and point it to AAG if the user can view stats, manage modules or if Protect is active.
	 * Otherwise and only if user is allowed to see the Jetpack Admin, the Dashboard sub-link is added but pointed to Apps tab.
	 *
	 * Works in Dev Mode or when user is connected.
	 *
	 * @since 4.3
	 */
	function jetpack_add_dashboard_sub_nav_item() {
		if ( Jetpack::is_development_mode() || Jetpack::is_active() ) {
			global $submenu;
			if ( current_user_can( 'jetpack_manage_modules' ) || Jetpack::is_module_active( 'protect' ) || current_user_can( 'view_stats' ) ) {
				$submenu['jetpack'][] = array( __( 'Dashboard', 'jetpack' ), 'jetpack_admin_page', Jetpack::admin_url( 'page=jetpack#/dashboard' ) );
			} elseif ( current_user_can( 'jetpack_admin_page' ) ) {
				$submenu['jetpack'][] = array( __( 'Dashboard', 'jetpack' ), 'jetpack_admin_page', Jetpack::admin_url( 'page=jetpack#/apps' ) );
			}
		}
	}

	/**
	 * If user is allowed to see the Jetpack Admin, add Settings sub-link.
	 *
	 * @since 4.3
	 */
	function jetpack_add_settings_sub_nav_item() {
		if ( ( Jetpack::is_development_mode() || Jetpack::is_active() ) && current_user_can( 'jetpack_admin_page' ) ) {
			global $submenu;
			$submenu['jetpack'][] = array( __( 'Settings', 'jetpack' ), 'jetpack_admin_page', Jetpack::admin_url( 'page=jetpack#/settings' ) );
		}
	}

	function add_fallback_head_meta() {
		echo '<meta http-equiv="refresh" content="0; url=?page=jetpack_modules">';
	}

	function add_noscript_head_meta() {
		echo '<noscript>';
		$this->add_fallback_head_meta();
		echo '</noscript>';
	}

	function add_legacy_browsers_head_script() {
		echo
			"<script type=\"text/javascript\">\n"
			. "/*@cc_on\n"
			. "if ( @_jscript_version <= 10) {\n"
			. "window.location.href = '?page=jetpack_modules';\n"
			. "}\n"
			. "@*/\n"
			. "</script>";
	}

	function jetpack_menu_order( $menu_order ) {
		$jp_menu_order = array();

		foreach ( $menu_order as $index => $item ) {
			if ( $item != 'jetpack' )
				$jp_menu_order[] = $item;

			if ( $index == 0 )
				$jp_menu_order[] = 'jetpack';
		}

		return $jp_menu_order;
	}

	// Render the configuration page for the module if it exists and an error
	// screen if the module is not configurable
	// @todo remove when real settings are in place
	function render_nojs_configurable( $module_name ) {
		$module_name = preg_replace( '/[^\da-z\-]+/', '', $_GET['configure'] );

		include_once( JETPACK__PLUGIN_DIR . '_inc/header.php' );
		echo '<div class="wrap configure-module">';

		if ( Jetpack::is_module( $module_name ) && current_user_can( 'jetpack_configure_modules' ) ) {
			Jetpack::admin_screen_configure_module( $module_name );
		} else {
			echo '<h2>' . esc_html__( 'Error, bad module.', 'jetpack' ) . '</h2>';
		}

		echo '</div><!-- /wrap -->';
	}

	function page_render() {
		// Handle redirects to configuration pages
		if ( ! empty( $_GET['configure'] ) ) {
			return $this->render_nojs_configurable( $_GET['configure'] );
		}

		/** This action is already documented in views/admin/admin-page.php */
		do_action( 'jetpack_notices' );

		echo file_get_contents( JETPACK__PLUGIN_DIR . '/_inc/build/static.html' );
	}

	function get_i18n_data() {
		$locale_data = @file_get_contents( JETPACK__PLUGIN_DIR . '/languages/json/jetpack-' . get_locale() . '.json' );
		if ( $locale_data ) {
			return $locale_data;
		} else {
			return '{}';
		}
	}

	/**
	 * Gets array of any Jetpack notices that have been dismissed.
	 *
	 * @since 4.0.1
	 * @return mixed|void
	 */
	function get_dismissed_jetpack_notices() {
		$jetpack_dismissed_notices = get_option( 'jetpack_dismissed_notices', array() );
		/**
		 * Array of notices that have been dismissed.
		 *
		 * @since 4.0.1
		 *
		 * @param array $jetpack_dismissed_notices If empty, will not show any Jetpack notices.
		 */
		$dismissed_notices = apply_filters( 'jetpack_dismissed_notices', $jetpack_dismissed_notices );
		return $dismissed_notices;
	}

	function jetpack_get_tracks_user_data() {
		if ( ! $user_data = Jetpack::get_connected_user_data() ) {
			return false;
		}

		return array(
			'userid' => $user_data['ID'],
			'username' => $user_data['login'],
		);
	}

	function page_admin_scripts() {
		if ( $this->is_redirecting ) {
			return; // No need for scripts on a fallback page
		}

		$rtl = is_rtl() ? '.rtl' : '';

		// Enqueue jp.js and localize it
		wp_enqueue_script( 'react-plugin', plugins_url( '_inc/build/admin.js', JETPACK__PLUGIN_FILE ), array(), JETPACK__VERSION, true );
		wp_enqueue_style( 'dops-css', plugins_url( "_inc/build/admin.dops-style$rtl.css", JETPACK__PLUGIN_FILE ), array(), JETPACK__VERSION );
		wp_enqueue_style( 'components-css', plugins_url( "_inc/build/style.min$rtl.css", JETPACK__PLUGIN_FILE ), array(), JETPACK__VERSION );

		// Required for Analytics
		wp_enqueue_script( 'jp-tracks', '//stats.wp.com/w.js?48' );

		$localeSlug = explode( '_', get_locale() );
		$localeSlug = $localeSlug[0];

		// Collecting roles that can view site stats
		$stats_roles = array();
		$enabled_roles = function_exists( 'stats_get_option' ) ? stats_get_option( 'roles' ) : array( 'administrator' );
		foreach( get_editable_roles() as $slug => $role ) {
			$stats_roles[ $slug ] = array(
				'name' => translate_user_role( $role['name'] ),
				'canView' => in_array( $slug, $enabled_roles, true ),
			);
		}

		// Add objects to be passed to the initial state of the app
		wp_localize_script( 'react-plugin', 'Initial_State', array(
			'WP_API_root' => esc_url_raw( rest_url() ),
			'WP_API_nonce' => wp_create_nonce( 'wp_rest' ),
			'pluginBaseUrl' => plugins_url( '', JETPACK__PLUGIN_FILE ),
			'connectionStatus' => array(
				'isActive'  => Jetpack::is_active(),
				'isStaging' => Jetpack::is_staging_site(),
				'devMode'   => array(
					'isActive' => Jetpack::is_development_mode(),
					'constant' => defined( 'JETPACK_DEV_DEBUG' ) && JETPACK_DEV_DEBUG,
					'url'      => site_url() && false === strpos( site_url(), '.' ),
					'filter'   => apply_filters( 'jetpack_development_mode', false ),
				),
				'isPublic'	=> '1' == get_option( 'blog_public' ),
			),
			'dismissedNotices' => $this->get_dismissed_jetpack_notices(),
			'isDevVersion' => Jetpack::is_development_version(),
			'currentVersion' => JETPACK__VERSION,
			'happinessGravIds' => jetpack_get_happiness_gravatar_ids(),
			'getModules' => Jetpack_Core_Json_Api_Endpoints::get_modules(),
			'showJumpstart' => jetpack_show_jumpstart(),
			'rawUrl' => Jetpack::build_raw_urls( get_home_url() ),
			'adminUrl' => esc_url( admin_url() ),
			'stats' => array(
				// data is populated asynchronously on page load
				'data'  => array(
					'general' => false,
					'day'     => false,
					'week'    => false,
					'month'   => false,
				),
				'roles' => $stats_roles,
			),
			'settingNames' => array(
				'jetpack_holiday_snow_enabled' => function_exists( 'jetpack_holiday_snow_option_name' ) ? jetpack_holiday_snow_option_name() : false,
			),
			'userData' => array(
				'othersLinked' => jetpack_get_other_linked_users(),
				'currentUser'  => jetpack_current_user_data(),
			),
			'locale' => $this->get_i18n_data(),
			'localeSlug' => $localeSlug,
			'jetpackStateNotices' => array(
				'messageCode' => Jetpack::state( 'message' ),
				'errorCode' => Jetpack::state( 'error' ),
				'errorDescription' => Jetpack::state( 'error_description' ),
			),
			'tracksUserData' => $this->jetpack_get_tracks_user_data(),
		) );
	}
}

/*
 * List of happiness Gravatar IDs
 *
 * @todo move to functions.global.php when available
 * @since 4.1.0
 * @return array
 */
function jetpack_get_happiness_gravatar_ids() {
	return array(
		'623f42e878dbd146ddb30ebfafa1375b',
		'561be467af56cefa58e02782b7ac7510',
		'd8ad409290a6ae7b60f128a0b9a0c1c5',
		'790618302648bd80fa8a55497dfd8ac8',
		'6e238edcb0664c975ccb9e8e80abb307',
		'4e6c84eeab0a1338838a9a1e84629c1a',
		'9d4b77080c699629e846d3637b3a661c',
		'4626de7797aada973c1fb22dfe0e5109',
		'190cf13c9cd358521085af13615382d5',
		'f7006d10e9f7dd7bea89a001a2a2fd59',
		'16acbc88e7aa65104ed289d736cb9698',
		'4d5ad4219c6f676ea1e7d40d2e8860e8',
		'e301f7d01b09e7578fdfc1b1ec1bc08d',
		'42f4c73f5337486e199f6e3b3910f168',
		'e7b26de48e76498cff880abca1eed8da',
		'764fb02aaae2ff64c0625c763d82b74e',
		'4988305772319fb9bc8fce0a7acb3aa1',
		'5d8695c4b81592f1255721d2644627ca',
		'0e2249a7de3404bc6d5207a45e911187',
	);
}

/*
 * Only show Jump Start on first activation.
 * Any option 'jumpstart' other than 'new connection' will hide it.
 *
 * The option can be of 4 things, and will be stored as such:
 * new_connection      : Brand new connection - Show
 * jumpstart_activated : Jump Start has been activated - dismiss
 * jetpack_action_taken: Manual activation of a module already happened - dismiss
 * jumpstart_dismissed : Manual dismissal of Jump Start - dismiss
 *
 * @todo move to functions.global.php when available
 * @since 3.6
 * @return bool | show or hide
 */
function jetpack_show_jumpstart() {
	if ( ! Jetpack::is_active() ) {
		return false;
	}
	$jumpstart_option = Jetpack_Options::get_option( 'jumpstart' );

	$hide_options = array(
		'jumpstart_activated',
		'jetpack_action_taken',
		'jumpstart_dismissed'
	);

	if ( ! $jumpstart_option || in_array( $jumpstart_option, $hide_options ) ) {
		return false;
	}

	return true;
}

/*
 * Checks to see if there are any other users available to become primary
 * Users must both:
 * - Be linked to wpcom
 * - Be an admin
 *
 * @return mixed False if no other users are linked, Int if there are.
 */
function jetpack_get_other_linked_users() {
	// If only one admin
	$all_users = count_users();
	if ( 2 > $all_users['avail_roles']['administrator'] ) {
		return false;
	}

	$users = get_users();
	$available = array();
	// If no one else is linked to dotcom
	foreach ( $users as $user ) {
		if ( isset( $user->caps['administrator'] ) && Jetpack::is_user_connected( $user->ID ) ) {
			$available[] = $user->ID;
		}
	}

	if ( 2 > count( $available ) ) {
		return false;
	}

	return count( $available );
}

/*
 * Gather data about the master user.
 *
 * @since 4.1.0
 *
 * @return array
 */
function jetpack_master_user_data() {
	$masterID = Jetpack_Options::get_option( 'master_user' );
	if ( ! get_user_by( 'id', $masterID ) ) {
		return false;
	}

	$jetpack_user = get_userdata( $masterID );
	$wpcom_user   = Jetpack::get_connected_user_data( $jetpack_user->ID );
	$gravatar     = get_avatar( $jetpack_user->ID, 40 );

	$master_user_data = array(
		'jetpackUser' => $jetpack_user,
		'wpcomUser'   => $wpcom_user,
		'gravatar'    => $gravatar,
	);

	return $master_user_data;
}

/*
 * Gather data about the current user.
 *
 * @since 4.1.0
 *
 * @return array
 */
function jetpack_current_user_data() {
	global $current_user;
	$is_master_user = $current_user->ID == Jetpack_Options::get_option( 'master_user' );
	$dotcom_data    = Jetpack::get_connected_user_data();

	$current_user_data = array(
		'isConnected' => Jetpack::is_user_connected( $current_user->ID ),
		'isMaster'    => $is_master_user,
		'username'    => $current_user->user_login,
		'wpcomUser'   => $dotcom_data,
		'gravatar'    => get_avatar( $current_user->ID, 40 ),
		'permissions' => array(
			'admin_page'         => current_user_can( 'jetpack_admin_page' ),
			'connect'            => current_user_can( 'jetpack_connect' ),
			'disconnect'         => current_user_can( 'jetpack_disconnect' ),
			'manage_modules'     => current_user_can( 'jetpack_manage_modules' ),
			'network_admin'      => current_user_can( 'jetpack_network_admin_page' ),
			'network_sites_page' => current_user_can( 'jetpack_network_sites_page' ),
			'edit_posts'         => current_user_can( 'edit_posts' ),
			'manage_options'     => current_user_can( 'manage_options' ),
			'view_stats'		 => current_user_can( 'view_stats' ),
		),
	);

	return $current_user_data;
}
