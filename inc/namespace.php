<?php
namespace AM\LastViewedPosts;

/**
 * Kick it off!
 *
 * Register actions and filters required for the plugin. This runs
 * as WordPress includes the file.
 */
function bootstrap() {
	global_setup();
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );
	add_action( 'widgets_init', __NAMESPACE__ . '\\widget_init' );
}

/**
 * Backward compatibility for globals used previously.
 *
 * Checks if any of the globals used in previous versions are set
 * and shims them to the new filter if they are. The globals in
 * question are:
 *
 * - $zg_cookie_expire
 * - $zg_number_of_posts
 * - $zg_recognize_pages
 *
 * This is done by registering filters to run on the hook returning
 * the global value if it is set. To avoid interferring with plugins
 * using the more recent filter method, these are registered to run
 * early (priority 5).
 */
function global_setup() {
	global $zg_cookie_expire, $zg_number_of_posts, $zg_recognize_pages;

	if ( isset( $zg_cookie_expire ) ) {
		add_filter(
			'am.last_viewed_posts.expiration_period',
			function ( $expiration_period ) use ( $zg_cookie_expire ) {
				return $zg_cookie_expire;
			},
			5
		);
	}

	if ( isset( $zg_number_of_posts ) ) {
		add_filter(
			'am.last_viewed_posts.number_posts_to_display',
			function ( $number_of_posts ) use ( $zg_number_of_posts ) {
				return $zg_number_of_posts;
			},
			5
		);
	}

	if ( isset( $zg_recognize_pages ) ) {
		add_filter(
			'am.last_viewed_posts.post_types',
			function ( $post_types ) use ( $zg_recognize_pages ) {
				if ( ! $zg_recognize_pages ) {
					$post_types = array_diff( array_unique( (array) $post_types ), array( 'page' ) );
				}
				return $post_types;
			},
			5
		);
	}
}

/**
 * Returns how long the array of posts is valid for in seconds.
 *
 * @return int Period to store recent posts in seconds. Default 360 days.
 */
function expiration_period() {
	/**
	 * Modify how long to store the user's recent posts in days.
	 *
	 * @param int $expiration_period How many days to store the user's recent posts. Default 360.
	 */
	$expiration_period = (int) apply_filters( 'am.last_viewed_posts.expiration_period', 360 );
	return $expiration_period * DAY_IN_SECONDS;
}

/**
 * Returns number of posts to store as recently visited.
 *
 * @return int Number of recent posts to display. Default 10.
 */
function number_posts_to_display() {
	/**
	 * Modify the number of posts to display to a visitor.
	 *
	 * @param int $posts_displayed How many posts to display. Default 10.
	 */
	return (int) apply_filters( 'am.last_viewed_posts.number_posts_to_display', 10 );
}

/**
 * Returns post types to include in recently visited list.
 *
 * @return string[] Array of post types to include. Default: post, page.
 */
function include_post_types() {
	$default_post_types = array( 'post', 'page' );
	/**
	 * Modify post types to display to visitors.
	 *
	 * @param string[] $post_types Array of types to display. Default: post, page.
	 */
	$post_types = (array) apply_filters( 'am.last_viewed_posts.post_types', $default_post_types );

	if ( empty( $post_types ) ) {
		// Return to default.
		return $default_post_types;
	}

	return $post_types;
}

/**
 * Return array populated with data stored in legacy cookie
 *
 * @return array {
 *     Data stored in legacy cookie.
 *
 *     @type array[] $posts  Associative array of post data.
 *     @type string  $path   Path used when setting cookie.
 *     @type string  $domain Domain used when setting cookie.
 * } | false
 */
function get_legacy_cookies() {
	if ( empty( $_COOKIE['WP-LastViewedPosts'] ) ) {
		return false;
	}

	$post_ids = safe_maybe_unserialize( wp_unslash( $_COOKIE['WP-LastViewedPosts'] ) );
	$home_url = wp_parse_url( home_url( '/' ) );
	$return   = array(
		'path'   => ! empty( $home_url['path'] ) ? $home_url['path'] : '/',
		'domain' => ! empty( $home_url['host'] ) ? ".{$home_url['host']}" : '',
		'posts'  => array(),
	);

	if ( ! is_array( $post_ids ) ) {
		return false;
	}

	// Little hack to warm the caches.
	get_posts(
		array(
			'include'     => $post_ids,
			'numberposts' => number_posts_to_display(),
		)
	);

	foreach ( $post_ids as $post_id ) {
		$return['posts'][] = array(
			'id'    => (int) $post_id,
			'url'   => get_permalink( $post_id ),
			'title' => wp_strip_all_tags( get_the_title( $post_id ) ),
		);
	}

	return $return;
}

function safe_maybe_unserialize( $data ) {
	if ( is_serialized( $data ) ) { // Don't attempt to unserialize data that wasn't serialized going in.
		return @unserialize( trim( $data ), array( 'allowed_classes' => false ) );
	}

	return $data;
}

/**
 * Enqueue JavaScript and CSS for recent posts plugin.
 */
function enqueue_scripts() {
	wp_enqueue_script(
		'am.view_last_posts',
		plugins_url( '/assets/index.js', __DIR__ ),
		array(),
		'1.0.0',
		true
	);

	$script_settings = array(
		'save_url'       => is_singular( include_post_types() ) && ! is_home() && ! is_front_page(),
		'post_id'        => get_the_ID(),
		'post_permalink' => get_permalink(),
		'post_title'     => wp_strip_all_tags( get_the_title() ),
		'home_url'       => home_url(),
		'legacy'         => get_legacy_cookies(),
		'expiry_period'  => expiration_period(),
		'posts_to_store' => number_posts_to_display(),
	);

	wp_add_inline_script(
		'am.view_last_posts',
		'
		amViewLastPosts = window.amViewLastPosts || {};
		amViewLastPosts.settings = ' . wp_json_encode( $script_settings ) . ';',
		'before'
	);

	// Null stylesheets used for inline styles need to be registered then enqueued.
	wp_register_style( 'am.view_last_posts', '', array(), '1.0.0' );
	wp_add_inline_style(
		'am.view_last_posts',
		'
		.am\\.last-viewed-posts\\.display-none.am\\.last-viewed-posts\\.display-none {
			display:none !important;
		}
		'
	);
	wp_enqueue_style( 'am.view_last_posts' );
}

/**
 * Initialise the widget.
 */
function widget_init() {
	register_widget( __NAMESPACE__ . '\\Widget' );
}

/**
 * Display list of recently viewed posts.
 */
function recently_viewed() {
	?>
	<ul class="viewed_posts am.last-viewed-posts.display-none"></ul>
	<script>
		( 'amViewLastPosts' in window && 'script' in amViewLastPosts && amViewLastPosts.script(amViewLastPosts.settings, window, document) )
	</script>
	<?php
}
