<?php

class Jetpack_Comic {
	const POST_TYPE = 'jetpack-comic';

	static function init() {
		static $instance = false;

		if ( ! $instance )
			$instance = new Jetpack_Comic;

		return $instance;
	}

	/**
	 * Conditionally hook into WordPress.
	 *
	 * Themes must declare that they support this module by adding
	 * add_theme_support( 'jetpack-comic' ); during after_setup_theme.
	 *
	 * If no theme support is found there is no need to hook into
	 * WordPress. We'll just return early instead.
	 */
	function __construct() {
		// Make sure the post types are loaded for imports
		add_action( 'import_start', array( $this, 'register_post_types' ) );

		// Add to REST API post type whitelist
		add_filter( 'rest_api_allowed_post_types', array( $this, 'allow_rest_api_type' ) );

		// If called via REST API, we need to register later in lifecycle
		add_action( 'restapi_theme_init', array( $this, 'maybe_register_post_types' ) );

		// Return early if theme does not support Jetpack Comic.
		if ( ! ( $this->site_supports_comics() ) )
			return;

		$this->register_post_types();

		add_action( 'pre_get_posts', array( $this, 'add_posts_to_loop' ) );

		// In order for the Feedbag job to find Comic posts, we need to circumvent any pretty
		// URLs in the RSS feed given to Feedbag in favor of /?p=123&post_type=jetpack-comic
		add_filter( 'the_permalink_rss', array( $this, 'custom_permalink_for_feedbag' ) );

		// There are some cases (like when Feedbag is fetching posts) that the comics
		// post type needs to be registered no matter what, but none of the UI needs to be
		// available.

		// Enable Omnisearch for Comic posts.
		if ( class_exists( 'Jetpack_Omnisearch_Posts' ) )
			new Jetpack_Omnisearch_Posts( self::POST_TYPE );

		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );

		if ( function_exists( 'queue_publish_post' ) ) {
			add_action( 'publish_jetpack-comic', 'queue_publish_post', 10, 2 );
		}

		add_action( 'pre_get_posts', array( $this, 'include_in_feeds' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'manage_posts_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'manage_posts_custom_column' ), 10, 2 );
		add_image_size( 'jetpack-comic-thumb', 150, 0, false );

		// Enable front-end uploading for users special enough.
		if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' ) ) {
			add_action( 'wp_ajax_jetpack_comic_upload', array( $this, 'upload' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
		}

		/**
		 * Add a "Convert to Comic" and "Convert to Post" option to the bulk
		 * edit dropdowns.
		 */
		add_action( 'admin_footer-edit.php', array( $this, 'admin_footer' ) );
		add_action( 'load-edit.php', array( $this, 'bulk_edit' ) );
		add_action( 'admin_notices', array( $this, 'bulk_edit_notices' ) );

	}

	public function admin_footer() {
		$post_type = get_post_type();

		?>
		<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				<?php if ( ! $post_type || 'post' == $post_type ) { ?>
					$( '<option>' )
						.val( 'post2comic' )
						.text( <?php echo json_encode( __( 'Convert to Comic', 'jetpack' ) ); ?> )
						.appendTo( "select[name='action'], select[name='action2']" );
				<?php } ?>
				<?php if ( ! $post_type || self::POST_TYPE == $post_type ) { ?>
					$( '<option>' )
						.val( 'comic2post' )
						.text( <?php echo json_encode( __( 'Convert to Post', 'jetpack' ) ); ?> )
						.appendTo( "select[name='action'], select[name='action2']" );
				<?php } ?>

				$( '#message.jetpack-comic-post-type-conversion' ).remove().insertAfter( $( '.wrap h2:first' ) ).show();
			});
		</script>
		<?php
	}

	/**
	 * Handle the "Convert to [Post|Comic]" bulk action.
	 */
	public function bulk_edit() {
		if ( empty( $_REQUEST['post'] ) )
			return;

		$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
		$action = $wp_list_table->current_action();

		check_admin_referer( 'bulk-posts' );

		if ( 'post2comic' == $action || 'comic2post' == $action ) {
			if ( ! current_user_can( 'publish_posts' ) )
				wp_die( __( 'You are not allowed to make this change.', 'jetpack' ) );

			$post_ids = array_map( 'intval', $_REQUEST['post'] );

			$modified_count = 0;

			foreach ( $post_ids as $post_id ) {
				$destination_post_type = ( $action == 'post2comic' ) ? self::POST_TYPE : 'post';
				$origin_post_type = ( $destination_post_type == 'post' ) ? self::POST_TYPE : 'post';

				if ( current_user_can( 'edit_post', $post_id ) ) {
					$post = get_post( $post_id );

					// Only convert posts that are post => comic or comic => post.
					// (e.g., Ignore comic => comic, page => post, etc. )
					if ( $post->post_type != $destination_post_type && $post->post_type == $origin_post_type ) {
						$post_type_object = get_post_type_object( $destination_post_type );

						if ( current_user_can( $post_type_object->cap->publish_posts ) ) {
							set_post_type( $post_id, $destination_post_type );
							$modified_count++;
						}
					}
				}
			}

			$sendback = remove_query_arg( array( 'exported', 'untrashed', 'deleted', 'ids' ), wp_get_referer() );

			if ( ! $sendback )
				$sendback = add_query_arg( array( 'post_type', get_post_type() ), admin_url( 'edit.php' ) );

			$pagenum = $wp_list_table->get_pagenum();
			$sendback = add_query_arg( array( 'paged' => $pagenum, 'post_type_changed' => $modified_count ), $sendback );

			wp_safe_redirect( $sendback );
			exit();
		}
	}

	/**
	 * Show the post conversion success notice.
	 */
	public function bulk_edit_notices() {
		global $pagenow;

		if ( 'edit.php' == $pagenow && ! empty( $_GET['post_type_changed'] ) ) {
			?><div id="message" class="updated below-h2 jetpack-comic-post-type-conversion" style="display: none;"><p><?php
			printf( _n( 'Post converted.', '%s posts converted', $_GET['post_type_changed'], 'jetpack' ), number_format_i18n( $_GET['post_type_changed'] ) );
			?></p></div><?php
		}
	}

	public function register_scripts() {
		if( is_rtl() ) {
			wp_enqueue_style( 'jetpack-comics-style', plugins_url( 'comics/rtl/comics-rtl.css', __FILE__ ) );
		} else {
			wp_enqueue_style( 'jetpack-comics-style', plugins_url( 'comics/comics.css', __FILE__ ) );
		}

		wp_enqueue_script( 'jetpack-comics', plugins_url( 'comics/comics.js', __FILE__ ), array( 'jquery', 'jquery.spin' ) );

		$options = array(
			'nonce' => wp_create_nonce( 'jetpack_comic_upload_nonce' ),
			'writeURL' => admin_url( 'admin-ajax.php?action=jetpack_comic_upload' ),
			'labels' => array(
				'dragging' => __( 'Drop images to upload', 'jetpack' ),
				'uploading' => __( 'Uploading...', 'jetpack' ),
				'processing' => __( 'Processing...', 'jetpack' ),
				'unsupported' => __( "Sorry, your browser isn't supported. Upgrade at browsehappy.com.", 'jetpack' ),
				'invalidUpload' => __( 'Only images can be uploaded here.', 'jetpack' ),
				'error' => __( "Your upload didn't complete; try again later or cross your fingers and try again right now.", 'jetpack' ),
			)
		);

		wp_localize_script( 'jetpack-comics', 'Jetpack_Comics_Options', $options );
	}

	public function admin_enqueue_scripts() {
		wp_enqueue_style( 'jetpack-comics-admin', plugins_url( 'comics/admin.css', __FILE__ ) );
	}

	public function maybe_register_post_types() {
		// Return early if theme does not support Jetpack Comic.
		if ( ! ( $this->site_supports_comics() ) )
			return;

		$this->register_post_types();
	}

	function register_post_types() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type( self::POST_TYPE, array(
			'description' => __( 'Comics', 'jetpack' ),
			'labels' => array(
				'name'                  => esc_html__( 'Comics',                   'jetpack' ),
				'singular_name'         => esc_html__( 'Comic',                    'jetpack' ),
				'menu_name'             => esc_html__( 'Comics',                   'jetpack' ),
				'all_items'             => esc_html__( 'All Comics',               'jetpack' ),
				'add_new'               => esc_html__( 'Add New',                  'jetpack' ),
				'add_new_item'          => esc_html__( 'Add New Comic',            'jetpack' ),
				'edit_item'             => esc_html__( 'Edit Comic',               'jetpack' ),
				'new_item'              => esc_html__( 'New Comic',                'jetpack' ),
				'view_item'             => esc_html__( 'View Comic',               'jetpack' ),
				'search_items'          => esc_html__( 'Search Comics',            'jetpack' ),
				'not_found'             => esc_html__( 'No Comics found',          'jetpack' ),
				'not_found_in_trash'    => esc_html__( 'No Comics found in Trash', 'jetpack' ),
				'filter_items_list'     => esc_html__( 'Filter comics list',       'jetpack' ),
				'items_list_navigation' => esc_html__( 'Comics list naviagation',  'jetpack' ),
				'items_list'            => esc_html__( 'Comics list',              'jetpack' ),
			),
			'supports' => array(
				'title',
				'editor',
				'thumbnail',
				'comments',
				'revisions',
				'publicize', // Jetpack
				'subscriptions', // wpcom
				'shortlinks', // Jetpack
			),
			'rewrite' => array(
				'slug'       => 'comic',
				'with_front' => false,
			),
			'taxonomies' => array(
				'category',
				'post_tag',
			),
			// Only make the type public for sites that support Comics.
			'public'          => true,
			'menu_position'   => 5, // below Posts
			'map_meta_cap'    => true,
			'has_archive'     => true,
			'query_var'       => 'comic',
		) );
	}

	public function manage_posts_columns( $columns ) {
		$new_columns = array(
			'preview-jetpack-comic' => __( 'Preview', 'jetpack' ),
		);
		return array_merge( array_slice( $columns, 0, 2 ), $new_columns, array_slice( $columns, 2 ) );
	}

	public function manage_posts_custom_column( $column_name, $post_ID ) {
		if ( 'preview-jetpack-comic' == $column_name && has_post_thumbnail( $post_ID ) ) {
			echo get_the_post_thumbnail( $post_ID, 'jetpack-comic-thumb' );
		}
	}

	/**
	 * The function url_to_postid() doesn't handle pretty permalinks
	 * for CPTs very well. When we're generating an RSS feed to be consumed
	 * for Feedbag (the Reader's feed storage mechanism), eschew
	 * a pretty URL for one that will get the post into the Reader.
	 *
	 * @see http://core.trac.wordpress.org/ticket/19744
	 * @param string $permalink The existing (possibly pretty) permalink.
	 */
	public function custom_permalink_for_feedbag( $permalink ) {
		global $post;

		if ( ! empty( $GLOBALS['is_feedbag_rss_script'] ) && self::POST_TYPE == $post->post_type ) {
			$permalink = home_url( add_query_arg( array( 'p' => $post->ID, 'post_type' => self::POST_TYPE ), '?' ) );
		}

		return $permalink;
	}

	/*
	 * Update messages for the Comic admin.
	 */
	function updated_messages( $messages ) {
		global $post;

		$messages['jetpack-comic'] = array(
			0  => '', // Unused. Messages start at index 1.
			1  => sprintf( __( 'Comic updated. <a href="%s">View comic</a>', 'jetpack'), esc_url( get_permalink( $post->ID ) ) ),
			2  => esc_html__( 'Custom field updated.', 'jetpack' ),
			3  => esc_html__( 'Custom field deleted.', 'jetpack' ),
			4  => esc_html__( 'Comic updated.', 'jetpack' ),
			/* translators: %s: date and time of the revision */
			5  => isset( $_GET['revision'] ) ? sprintf( esc_html__( 'Comic restored to revision from %s', 'jetpack'), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			6  => sprintf( __( 'Comic published. <a href="%s">View comic</a>', 'jetpack' ), esc_url( get_permalink( $post->ID ) ) ),
			7  => esc_html__( 'Comic saved.', 'jetpack' ),
			8  => sprintf( __( 'Comic submitted. <a target="_blank" href="%s">Preview comic</a>', 'jetpack'), esc_url( add_query_arg( 'preview', 'true', get_permalink( $post->ID ) ) ) ),
			9  => sprintf( __( 'Comic scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview comic</a>', 'jetpack' ),
			// translators: Publish box date format, see http://php.net/date
			date_i18n( __( 'M j, Y @ G:i', 'jetpack' ), strtotime( $post->post_date ) ), esc_url( get_permalink($post->ID) ) ),
			10 => sprintf( __( 'Comic draft updated. <a target="_blank" href="%s">Preview comic</a>', 'jetpack' ), esc_url( add_query_arg( 'preview', 'true', get_permalink( $post->ID ) ) ) ),
		);

		return $messages;
	}

	/**
	 * Should this Custom Post Type be made available?
	 */
	public function site_supports_comics() {
		/**
		 * @todo: Extract this out into a wpcom only file.
		 */
		if ( 'blog-rss.php' == substr( $_SERVER['PHP_SELF'], -12 ) && count( $_SERVER['argv'] ) > 1 ) {
			// blog-rss.php isn't run in the context of the target blog when the init action fires,
			// so check manually whether the target blog supports comics.
			switch_to_blog( $_SERVER['argv'][1] );
			// The add_theme_support( 'jetpack-comic' ) won't fire on switch_to_blog, so check for Panel manually.
			$supports_comics = ( ( function_exists( 'site_vertical' ) && 'comics' == site_vertical() )
								|| current_theme_supports( self::POST_TYPE )
								|| get_stylesheet() == 'pub/panel' );
			restore_current_blog();

			/** This action is documented in modules/custom-post-types/nova.php */
			return (bool) apply_filters( 'jetpack_enable_cpt', $supports_comics, self::POST_TYPE );
		}

		$supports_comics = false;

		/**
		 * If we're on WordPress.com, and it has the menu site vertical.
		 * @todo: Extract this out into a wpcom only file.
		 */
		if ( function_exists( 'site_vertical' ) && 'comics' == site_vertical() ) {
			$supports_comics = true;
		}

		/**
		 * Else, if the current theme requests it.
		 */
		if ( current_theme_supports( self::POST_TYPE ) ) {
			$supports_comics = true;
		}

		/**
		 * Filter it in case something else knows better.
		 */
		/** This action is documented in modules/custom-post-types/nova.php */
		return (bool) apply_filters( 'jetpack_enable_cpt', $supports_comics, self::POST_TYPE );
	}

	/**
	 * Anywhere that a feed is displaying posts, show comics too.
	 *
	 * @param WP_Query $query
	 */
	public function include_in_feeds( $query ) {
		if ( ! $query->is_feed() )
			return;

		// Don't modify the query if the post type isn't public.
		if ( ! get_post_type_object( 'jetpack-comic' )->public )
			return;

		$query_post_types = $query->get( 'post_type' );

		if ( empty( $query_post_types ) )
			$query_post_types = 'post';

		if ( ! is_array( $query_post_types ) )
			$query_post_types = array( $query_post_types );

		if ( in_array( 'post', $query_post_types ) ) {
			$query_post_types[] = self::POST_TYPE;
			$query->set( 'post_type', $query_post_types );
		}
	}

	/**
	 * API endpoint for front-end image uploading.
	 */
	public function upload() {
		global $content_width;

		header( 'Content-Type: application/json' );

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'jetpack_comic_upload_nonce' ) )
			die( json_encode( array( 'error' => __( 'Invalid or expired nonce.', 'jetpack' ) ) ) );

		$_POST['action'] = 'wp_handle_upload';

		$image_id_arr = array();
		$image_error_arr = array();

		$i = 0;

		while ( isset( $_FILES['image_' . $i ] ) ) {
			// Create attachment for the image.
			$image_id = media_handle_upload( "image_$i", 0 );

			if ( is_wp_error( $image_id ) ) {
				$error = array( $image_id, $image_id->get_error_message() );
				array_push( $image_error_arr, $error );
			} else {
				array_push( $image_id_arr, $image_id );
			}

			$i++;
		}

		if ( count( $image_id_arr ) == 0 ) {
			// All image uploads failed.
			$rv = array( 'error' => '' );

			foreach ( $image_error_arr as $error )
				$rv['error'] .= $error[1] . "\n";
		}
		else {
			if ( count( $image_id_arr ) == 1 ) {
				$image_id = $image_id_arr[0];

				// Get the image
				$image_src = get_the_guid( $image_id );
				$image_dims = wp_get_attachment_image_src( $image_id, 'full' );

				// Take off 10px of width to account for padding and border. @todo make this smarter.
				if ( $content_width )
					$image_width = $content_width - 10;
				else
					$image_width = $image_dims[1] - 10;

				$post_content = '<a href="' . esc_attr( $image_src ) .'"><img src="' . esc_attr( $image_src ) . '?w=' . esc_attr( $image_width ) . '" alt="' . esc_attr( $_FILES['image_0']['name'] ) . '" class="size-full wp-image alignnone" id="i-' . esc_attr( $image_id ) . '" data-filename="' . esc_attr( $_FILES['image_0']['name'] ) . '" /></a>';
			}
			else {
				$post_content = '[gallery ids="' . esc_attr( implode( ',', $image_id_arr ) ) . '"]';
			}

			// Create a new post with the image(s)
			$post_id = wp_insert_post( array(
					'post_content' => $post_content,
					'post_type' => 'jetpack-comic',
					'post_status' => 'draft',
				),
				true
			);

			if ( is_wp_error( $post_id, 'WP_Error' ) ) {
				// Failed to create the post.
				$rv = array( 'error' => $post_id->get_error_message() );

				// Delete the uploaded images.
				foreach ( $image_id_arr as $image_id ) {
					wp_delete_post( $image_id, true );
				}
			}
			else {
				foreach ( $image_id_arr as $image_id ) {
					wp_update_post( array(
						'ID' => $image_id,
						'post_parent' => $post_id
					) );
				}

				if ( current_theme_supports( 'post-thumbnails' ) && count( $image_id_arr ) == 1 )
					set_post_thumbnail( $post_id, $image_id_arr[0] );

				$rv = array( 'url' => add_query_arg( array( 'post' => $post_id, 'action' => 'edit' ), admin_url( 'post.php' ) ) );
			}
		}

		die( json_encode( $rv ) );
	}

	public function add_posts_to_loop( $query ) {
		// Add comic posts to the tag and category pages.
		if ( ! is_admin() && $query->is_main_query() && ( $query->is_category() || $query->is_tag() ) ) {
			$post_types = $query->get( 'post_type' );

			if ( ! $post_types || 'post' == $post_types )
				$post_types = array( 'post', self::POST_TYPE );
			else if ( is_array( $post_types ) )
				$post_types[] = self::POST_TYPE;

			$query->set( 'post_type', $post_types );
		}

		return $query;
	}

	/**
	 * Add to REST API post type whitelist
	 */
	public function allow_rest_api_type( $post_types ) {
		$post_types[] = self::POST_TYPE;
		return $post_types;
	}

}

add_action( 'init', array( 'Jetpack_Comic', 'init' ) );


function comics_welcome_email( $welcome_email, $blog_id, $user_id, $password, $title, $meta ) {
	if ( ( isset( $meta['vertical'] ) && 'comics' == $meta['vertical'] ) || has_blog_sticker( 'vertical-comics', $blog_id ) ) {
		return __( "Welcome! Ready to publish your first strip?

Your webcomic's new site is ready to go. Get started by <a href=\"BLOG_URLwp-admin/customize.php#title\">setting your comic's title and tagline</a> so your readers know what it's all about.

Looking for more help with setting up your site? Check out the WordPress.com <a href=\"http://learn.wordpress.com/\">beginner's tutorial</a> and the <a href=\"http://en.support.wordpress.com/comics/\">guide to comics on WordPress.com</a>. Dive right in by <a href=\"BLOG_URLwp-admin/customize.php#title\">publishing your first strip!</a>

Lots of laughs,
The WordPress.com Team", 'jetpack' );
	}

	return $welcome_email;
}

add_filter( 'update_welcome_email_pre_replacement', 'comics_welcome_email', 10, 6 );
