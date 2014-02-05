<?php
/*
 * About WPEC customer profiles.
 *
 * WPEC customer profiles are nothing more than WordPress users created and used to hold information
 * related to a shoppers experience on a WPEC site.  Using WordPress users rather than a special purpose
 * database table makes all of the existing WordPress user functionality available when working with
 * customer profiles.
 *
 * Additionally, WordPress user functionality makes use of the built in caching capabilities within WordPress,
 * this makes user profile access quick.  All of this comes without the costs of additional code to maintain.
 *
 * WPEC, themes and plug-ins can add information to a customer profile at any time.  The functions making up the
 * API are in the wpsc-meta-customer.php.  You'll notice that the API for working with customer profiles mirrors
 * the WordPress API used to work with other types of meta data.  Customer meta can be manipulated just as
 * you would manipulate user meta, post meta, or any of the other types of meta available with a WordPress site.
 *
 * There are a few specifics about customer profiles that you may want to be aware of, but you shouldn't need
 * to know unless you are contributing to the WPEC meta functionality.
 *
 * Customer profile users when created are prefixed with '_wpsc_'.  Don't rely on this when creating database queries
 * because other plug-ins, or even administrators, can change user names.
 *
 * If you properly use the WPEC meta API, any meta you add to a customer profile will be prefixed with a
 * standard, multi-site safe, prefix.  This prefix is automatically removed when you retrieve meta values using
 * the WPEC meta API.
 *
 * Customer profiles have added to them  a meta value 'last_active' immediately upon creation.  This meta value
 * contains the UNIX timestamp (see PHP time() function) of the last meaningful change to the profile.
 * whatever end purposes you might want, but it's core purposes are two-fold.  (1) This value makes it possible to detect
 * abandoned carts and return cart stock, (2) and it makes it possible to detect temporary profiles that are no longer needed.
 * This value can also be used to implement advanced features like email reminders to customers that they have items in
 * their carts, or  haven't visited a store for a period of time.
 *
 * Customer profiles when created have added to them a role of Anonymous.  This makes it possible to distinguish which users
 * are created from WPEC operations from the users that are created by typical WordPress blog actions and other plug-ins.
 *
 * Customer profiles when automatically created have added to them a wpsc meta value "temporary_profile".  The presence of this
 * value indicates a WordPress user that will likely be deleted if the visitor doesn't take any future actions.
 * Because the above values (profile name, roles, etc.) can be altered by user interface or other plug-ins, having this
 * dedicated meta value gives us a safe and fast way of finding temporary profiles.
 *
 * If present, the value of the "temporary_profile" meta is automatically adjusted when the last_active time
 * is adjusted. The value will be the unix time stamp after which the profile can be marked for deletion.  When
 * the meta is first added to the newly created user profile the "safe to delete time" is set to the current time
 * plus 2 hours.  Using this method, visitor profiles that are created by mechanisms like aggregator framing web site pages
 * for user preview rather than browsing are more quickly deleted.
 *
 * Subsequent updates to last active move the safe to delete time to the last active time plus 48
 * hours.  This also means that customer profiles create for visitors that only do a single page view will
 * quickly be purged from the WordPress user table.  On the other hand visitors that view more than a single
 * page of a site will have profiles available for a longer time.
 *
 */

add_action( 'wpsc_set_cart_item'         , '_wpsc_action_update_current_customer_last_active' );
add_action( 'wpsc_add_item'              , '_wpsc_action_update_current_customer_last_active' );
add_action( 'wpsc_before_submit_checkout', '_wpsc_action_update_current_customer_last_active' );
add_action( 'wp_login'                   , '_wpsc_action_setup_customer'                  	  );

if ( is_admin() ) {
	add_action( 'load-users.php'             , '_wpsc_action_load_users'                          );
	add_filter( 'views_users'                , '_wpsc_filter_views_users'                         );
	add_filter( 'editable_roles'             , '_wpsc_filter_editable_roles'                      );
}

/**
 * Helper function for setting the customer cookie content and expiration
 *
 * @since  3.8.13
 * @access private
 * @param  mixed $cookie  Cookie data
 * @param  int   $expire  Expiration timestamp
 */
function _wpsc_set_customer_cookie( $cookie, $expire ) {
	$secure = is_ssl();
	setcookie( WPSC_CUSTOMER_COOKIE, $cookie, $expire, WPSC_CUSTOMER_COOKIE_PATH, COOKIE_DOMAIN, $secure, true );

	if ( $expire < time() )
		unset( $_COOKIE[WPSC_CUSTOMER_COOKIE] );
	else
		$_COOKIE[WPSC_CUSTOMER_COOKIE] = $cookie;
}

/**
 * In case the user is not logged in, create a new user account and store its ID
 * in a cookie
 *
 * @access public
 * @since 3.8.9
 * @return string Customer ID
 */
function _wpsc_create_customer_id() {

	$role = get_role( 'wpsc_anonymous' );

	if ( ! $role ) {
		add_role( 'wpsc_anonymous', __( 'Anonymous', 'wpsc' ) );
	}

	$username = '_' . wp_generate_password( 8, false, false );
	$password = wp_generate_password( 12, false );

	// filter gives chance for others to do some processing before the new user is handled
	$id   = wp_create_user( $username, $password );
	$user = new WP_User( $id );
	$user->set_role( 'wpsc_anonymous' );

	_wpsc_create_customer_id_cookie( $id );

	$now = time();
	wpsc_update_customer_meta( 'temporary_profile', $now + 2 * 60 * 60, $id ); // profile is retained for at least two hours
	wpsc_update_customer_meta( 'last_active', $now, $id );

	do_action( 'wpsc_create_customer_user' , $id , $user );

	return $id;
}

/**
 * Set up a dummy user account for bots.
 *
 * This is not an ideal solution but it prevents third party plugins from failing
 * because they rely on the customer meta being there no matter whether this request
 * is by a bot or not.
 *
 * @since 3.8.13
 * @access private
 */
function _wpsc_maybe_setup_bot_user() {
	if ( ! _wpsc_is_bot_user() )
		return;

	$username = '_wpsc_bot';
	$wp_user  = get_user_by( 'login', $username );

	if ( $wp_user === false ) {
		$password = wp_generate_password( 12, false );
		$id       = wp_create_user( $username, $password );
		$user     = new WP_User( $id );
		$user->set_role( 'wpsc_anonymous' );
	} else {
		$id = $wp_user->ID;
	}

	// pretend that the cookie exists but don't actually need to use setcookie()
	_wpsc_create_customer_id_cookie( $id, true );

	return $id;
}

/**
 * Create a cookie for a specific customer ID.
 *
 * You can also fake it by just assigning the cookie to $_COOKIE superglobal.
 *
 * @since  3.8.13
 * @access private
 * @param  int  $id      Customer ID
 * @param  boolean $fake_it Defaults to false
 */
function _wpsc_create_customer_id_cookie( $id, $fake_it = false ) {

	$expire = time() + WPSC_CUSTOMER_DATA_EXPIRATION; // valid for 48 hours
	$data   = $id . $expire;

	$user      = get_user_by( 'id', $id );
	$pass_frag = substr( $user->user_pass, 8, 4 );

	$key = wp_hash( $user->user_login . $pass_frag . '|' . $expire );

	$hash   = hash_hmac( 'md5', $data, $key );
	$cookie = $id . '|' . $expire . '|' . $hash;

	// store ID, expire and hash to validate later
	if ( $fake_it )
		$_COOKIE[ WPSC_CUSTOMER_COOKIE ] = $cookie;
	else
		_wpsc_set_customer_cookie( $cookie, $expire );
}

/**
 * Make sure the customer cookie is not compromised.
 *
 * @access public
 * @since 3.8.9
 * @return mixed Return the customer ID if the cookie is valid, false if otherwise.
 */
function _wpsc_validate_customer_cookie() {

	if ( is_admin() || ! isset( $_COOKIE[ WPSC_CUSTOMER_COOKIE ] ) ) {
		return false;
	}

	$cookie = $_COOKIE[ WPSC_CUSTOMER_COOKIE ];
	list( $id, $expire, $hash ) = $x = explode( '|', $cookie );
	$data = $id . $expire;

	// check to see if the ID is valid, it must be an integer, empty test is because old versions of php
	// can return true on empty string
	if ( ! empty( $id ) &&  ctype_digit( $id ) ) {
		$id = intval( $id );

		$user = get_user_by( 'id', $id );

		// if a user is found keep checking, user not found clear the cookie and return invalid
		if ( $user !== false ) {
			$pass_frag = substr( $user->user_pass, 8, 4 );
			$key       = wp_hash( $user->user_login . $pass_frag . '|' . $expire );
			$hmac      = hash_hmac( 'md5', $data, $key );

			// integrity check
			if ( $hmac == $hash ) {
				return $id;
			}
		}
	}

	// if we get to here the cookie or user is not valid
	_wpsc_set_customer_cookie( '', time() - 3600 );
	return false;
}

/**
 * Get current customer ID.
 *
 * If the user is logged in, return the user ID. Otherwise return the ID associated
 * with the customer's cookie.
 *
 * Implement your own system by hooking into 'wpsc_get_current_customer_id' filter.
 *
 * @access public
 * @since 3.8.9
 * @return mixed        User ID (if logged in) or customer cookie ID
 */
function wpsc_get_current_customer_id() {
	$id = apply_filters( 'wpsc_get_current_customer_id', null );

	if ( ! empty( $id ) )
		return $id;

	// if the user is logged in we use the user id
	if ( is_user_logged_in() ) {
		return get_current_user_id();
	} elseif ( isset( $_COOKIE[WPSC_CUSTOMER_COOKIE] ) ) {
		list( $id, $expire, $hash ) = explode( '|', $_COOKIE[WPSC_CUSTOMER_COOKIE] );
		return $id;
	}

	return _wpsc_create_customer_id();
}

/**
 * Setup current user object and customer ID as well as cart.
 *
 * @uses  do_action() Calls 'wpsc_setup_customer' after customer data is ready
 *
 * @access private
 * @since  3.8.13
 */
function _wpsc_action_setup_customer() {
	// if the customer cookie is invalid, unset it
	$id = _wpsc_validate_customer_cookie();

	// if a valid ID is present in the cookie, and the user is logged in,
	// it's time to merge the carts
	if ( isset( $_COOKIE[WPSC_CUSTOMER_COOKIE] ) && is_user_logged_in() ) {
		// merging cart requires the taxonomies to have been initialized
		if ( did_action( 'wpsc_register_taxonomies_after' ) ) {
			_wpsc_merge_cart();
		}
		else {
			add_action( 'wpsc_register_taxonomies_after', '_wpsc_merge_cart', 1 );
		}
	}

	// if this request is by a bot, prevent multiple account creation
	_wpsc_maybe_setup_bot_user();

	// initialize customer ID if it's not already there
	wpsc_get_current_customer_id();

	// setup the cart and restore its items
	wpsc_core_setup_cart();

	do_action( 'wpsc_setup_customer' );
}

/**
 * Merge cart from anonymous user with cart from logged in user
 *
 * @since 3.8.13
 * @access private
 */
function _wpsc_merge_cart() {
	$old_id = _wpsc_validate_customer_cookie();

	if ( ! $old_id ) {
		return;
	}

	$new_id = get_current_user_id();

	$old_cart = wpsc_get_customer_cart( $old_id );
	$items    = $old_cart->get_items();

	$new_cart = wpsc_get_customer_cart( $new_id );

	// first of all empty the old cart so that the claimed stock and related
	// hooks are released
	$old_cart->empty_cart();

	// add each item to the new cart
	foreach ( $items as $item ) {
		$new_cart->set_item( $item->product_id, array(
			'quantity'         => $item->quantity,
			'variation_values' => $item->variation_values,
			'custom_message'   => $item->custom_message,
			'provided_price'   => $item->provided_price,
			'time_requested'   => $item->time_requested,
			'custom_file'      => $item->custom_file,
			'is_customisable'  => $item->is_customisable,
			'meta'             => $item->meta
		) );
	}

	// The old profile is no longer needed
	_wpsc_abandon_temporary_customer_profile( $old_id );

	_wpsc_set_customer_cookie( '', time() - 3600 );
}

/**
 * Return the internal customer meta key, which depends on the blog prefix
 * if this is a multi-site installation.
 *
 * @since  3.8.13
 * @access private
 * @param  string $key Meta key
 * @return string      Internal meta key
 */
function _wpsc_get_customer_meta_key( $key ) {
	global $wpdb;

	$blog_prefix = is_multisite() ? $wpdb->get_blog_prefix() : '';
	return "{$blog_prefix}_wpsc_{$key}";
}

/**
 * Update the current customer's last active time
 *
 * @access private
 * @since  3.8.13
 */
function _wpsc_action_update_current_customer_last_active() {
	// get the current users id
	$id = wpsc_get_current_customer_id();

	// go through the common update routine that allows any users last active time to be changed
	wpsc_update_customer_last_active( $id );

	// also extend cookie expiration
	_wpsc_create_customer_id_cookie( $id );
}

/**
 * Is the user an automata not worthy of a WPEC profile to hold shopping cart and other info
 *
 * @access private
 * @since  3.8.13
 */
function _wpsc_is_bot_user() {

	$is_bot = false;

	if ( is_user_logged_in() ) {
		return false;
	}

	if ( strpos( $_SERVER['REQUEST_URI'], '?wpsc_action=rss' ) ) {
		return true;
	}

	// Cron jobs are not flesh originated
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true;
	}

	// XML RPC requests are probably from cybernetic beasts
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return true;
	}

	// coming to login first, after the user logs in we know they are a live being, until then they are something else
	if ( strpos( $_SERVER['PHP_SELF'], 'wp-login' ) || strpos( $_SERVER['PHP_SELF'], 'wp-register' ) ) {
		return true;
	}

	// even web servers talk to themselves when they think no one is listening
	if ( stripos( $_SERVER['HTTP_USER_AGENT'], 'wordpress' ) !== false ) {
		return true;
	}

	// the user agent could be google bot, bing bot or some other bot,  one would hope real user agents do not have the
	// string 'bot|spider|crawler|preview' in them, there are bots that don't do us the kindness of identifying themselves as such,
	// check for the user being logged in in a real user is using a bot to access content from our site
	$bot_agents_patterns = apply_filters( 'wpsc_bot_user_agents', array(
		'robot',
		'bot',
		'crawler',
		'spider',
		'preview',
	) );

	$pattern = '/(' . implode( '|', $bot_agents_patterns ) . ')/i';

	if ( preg_match( $pattern, $_SERVER['HTTP_USER_AGENT'] ) ) {
		return true;
	}

	// Are we feeding the masses?
	if ( is_feed() ) {
		return true;
	}

	// at this point we have eliminated all but the most obvious choice, a human (or cylon?)
	return apply_filters( 'wpsc_is_bot_user', false );
}

/**
 * Given a users.php view's HTML code, this function returns the user count displayed
 * in the view.
 *
 * If `count_users()` had implented caching, we could have just called that function again
 * instead of using this hack.
 *
 * @access private
 * @since  3.8.13.2
 * @param  string $view
 * @return int
 */
function _wpsc_extract_user_count( $view ) {
	global $wp_locale;
	if ( preg_match( '/class="count">\((.+)\)/', $view, $matches ) ) {
		return absint( str_replace( $wp_locale->number_format['thousands_sep'], '', $matches[1] ) );
	}

	return 0;
}

/**
 * Filter the user views so that Anonymous role is not displayed
 *
 * @since  3.8.13.2
 * @access private
 * @param  array $views
 * @return array
 */
function _wpsc_filter_views_users( $views ) {
	if ( isset( $views['wpsc_anonymous'] ) ) {
		// ugly hack to make the anonymous users not count towards "All"
		// really wish WordPress had a filter in count_users(), but in the mean time
		// this will do
		$anon_count = _wpsc_extract_user_count( $views['wpsc_anonymous'] );
		$all_count = _wpsc_extract_user_count( $views['all'] );
		$new_count = $all_count - $anon_count;
		$views['all'] = preg_replace( '/class="count">\(.+\)/', 'class="count">(' . number_format_i18n( $new_count ) . ')', $views['all'] );
	}

	unset( $views['wpsc_anonymous'] );
	return $views;
}

/**
 * Add the action necessary to filter out anonymous users
 *
 * @since 3.8.13.2
 * @access private
 */
function _wpsc_action_load_users() {
	add_action( 'pre_user_query', '_wpsc_action_pre_user_query', 10, 1 );
}

/**
 * Filter out anonymous users in "All" view
 *
 * @since 3.8.13.2
 * @access private
 * @param  WP_User_Query $query
 */
function _wpsc_action_pre_user_query( $query ) {
	global $wpdb;

	// only do this when we're viewing all users
	if ( ! empty( $query->query_vars['role'] ) )
		return;

	// if the site is multisite, we need to do things a bit differently
	if ( is_multisite() ) {
		// on Network Admin, a JOIN with usermeta is not possible (some users don't have capabilities set, so we fall back to matching user_login, although this is not ideal)
		if ( empty( $query->query_vars['blog_id'] ) ) {
			$query->query_where .= " AND $wpdb->users.user_login NOT LIKE '\_________'";
		} else {
			$query->query_where .= " AND CAST($wpdb->usermeta.meta_value AS CHAR) NOT LIKE '%" . like_escape( '"wpsc_anonymous"' ) . "%'";
		}
		return;
	}

	$cap_meta_query = array(
		array(
			'key'     => $wpdb->get_blog_prefix( $query->query_vars['blog_id'] ) . 'capabilities',
			'value'   => '"wpsc_anonymous"',
			'compare' => 'not like',
		)
	);

	$meta_query = new WP_Meta_Query( $cap_meta_query );
	$clauses = $meta_query->get_sql( 'user', $wpdb->users, 'ID', $query );

	$query->query_from .= $clauses['join'];
	$query->query_where .= $clauses['where'];
}

/**
 * Make sure Anonymous role not editable
 *
 * @since 3.8.13.2
 * @param  array $editable_roles
 * @return array
 */
function _wpsc_filter_editable_roles( $editable_roles ) {
	unset( $editable_roles['wpsc_anonymous'] );
	return $editable_roles;
}

/**
 * Attach a purchase log to our customer profile
 *
 * @access private
 * @since  3.8.14
 */
function _wpsc_set_purchase_log_customer_id( $data ) {

	// if there is a purchase log for this user we don't want to delete the
	// user id, even if the transaction isn't successful.  there may be useful
	// information in the customer profile related to the transaction
	wpsc_delete_customer_meta( 'temporary_profile' );

	// if there isn't already user id we set the user id of the current customer id
	if ( empty ( $data['user_ID'] ) ) {
		$id = wpsc_get_current_customer_id();
		$data['user_ID'] = $id;
	}

	return $data;
}

if ( ! is_user_logged_in() ) {
	add_filter( 'wpsc_purchase_log_update_data', '_wpsc_set_purchase_log_customer_id', 1, 1 );
	add_filter( 'wpsc_purchase_log_insert_data', '_wpsc_set_purchase_log_customer_id', 1, 1 );
}

/**
 * get the count of posts by the customer
 * @since 3.8.14
 * @access public
 * @return int
 */
function wpsc_customer_post_count( $id = false ) {

	if ( ! $id ) {
		$id = wpsc_get_current_customer_id();
	}

	return count_user_posts( $id );
}

/**
 * get the count of comments by the customer
 * @since 3.8.14
 * @access public
 * @param string $id
 * @return int
 */
function wpsc_customer_comment_count( $id = false ) {

	if ( ! $id )
		$id = wpsc_get_current_customer_id();

	global $wpdb;
	$count = $wpdb->get_var( 'SELECT COUNT(comment_ID) FROM ' . $wpdb->comments. ' WHERE user_id = "' . $id . '"' );

	if ( empty($count) || ! is_numeric( $count ) ) {
		$count = 0;
	}

	return $count;
}

/**
 * get the count of purchases by the customer
 * @since 3.8.14
 * @access public
 * @param string $id
 * @return int
 */
function wpsc_customer_purchase_count( $id = false ) {

	if ( ! $id )
		$id = wpsc_get_current_customer_id();

	global $wpdb;
	$count = $wpdb->get_var( 'SELECT COUNT(user_ID) FROM ' . WPSC_TABLE_PURCHASE_LOGS. ' WHERE user_id = "' . $id . '"' );

	if ( empty( $count ) || ! is_numeric( $count ) ) {
		$count = 0;
	}

	return $count;
}


function _wpsc_abandon_temporary_customer_profile( $id = false ) {

	if ( ! $id ) {
		$id = wpsc_get_current_customer_id();
	}

	// set the temporary profile keep until time to sometime in the past, the delete
	// processing will take care of the cleanup on the next processing cycle
	wpsc_update_customer_meta( 'temporary_profile', time() - 1, $id );
}
