<?php

/*
 * Customer meta API via AJAX.  Note that the API only permits access for the current customer (visitor)
 * because of security considerations.
 */

/**
 * Are we processing a customer meta AJAX request
 * @param string $action optional parameter to see if we are processing a specific action
 * @return boolean
 * @since 3.8.14
 */
function _wpsc_doing_customer_meta_ajax( $action = '' ) {

	$doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;

	$result = $doing_ajax && ( strpos( $_REQUEST['action'], 'wpsc_' ) === 0  );

	if ( $result && ! empty( $action ) ) {
		$result = $_REQUEST['action'] == $action;
	}
	return $result;
}


if ( ! defined( '_WPSC_USER_META_HOOK_PRIORITY' ) ) {
	define( '_WPSC_USER_META_HOOK_PRIORITY' , 2 );
}

if ( _wpsc_doing_customer_meta_ajax() ) {

	/**
	 * Validate the current customer, get the current customer id
	 * @param string
	 * @return JSON encoded array with results, results include original request parameters
	 * @since 3.8.14
	 */
	function wpsc_validate_customer_ajax() {
		// most of the validation should be done by the WPEC initialization, just return the current customer values
		$response = array( 'valid' => (_wpsc_validate_customer_cookie() !== false), 'id' => wpsc_get_current_customer_id() );
		$response = apply_filters( '_wpsc_validate_customer_ajax', $response );
		wp_send_json_success( $response );
	}


	/**
	 * Get customer meta values
	 * @uses$_POST[meta] array of meta keys to retrieve, if not present all
	 * 'registered' meta keys are returned.  See wpsc_checkout_unique_names() for the list
	 *  of registered meta keys.
	 *
	 * @return JSON encoded array with results, results include original request parameters
	 * @since 3.8.14
	 */

	function wpsc_get_customer_meta_ajax() {

		if ( empty( $_POST['meta']  ) ) {
			$meta = null;
		} elseif ( ! is_array( $meta ) ) {
			$meta = array( $meta );
		} else {
			$meta = $_POST['meta'];
		}

		$response = array( 'request' => $_REQUEST );
		$response = _wpsc_add_customer_meta_to_response( $response, $meta );

		$response['type'] = __( 'success', 'wpsc' );
		$response['error'] = '';

		wp_send_json_success( $response );
	}

	/**
	 * Update more than one customer meta
	 * @param meta_data - array of key value pairs to set
	 * @return JSON encoded array with results, results include original request parameters
	 * @since 3.8.14
	 */
	function wpsc_update_customer_meta_ajax() {

		$success = true;

		// we will echo back the request in the (likely async) response so that the client knows
		// which transaction the response matches
		$response = array( 'request' => $_REQUEST );

		// grab a copy of the current meta values so we can send back only items that have changed
		$response = _wpsc_add_customer_meta_to_response( $response, null, 'old_customer_meta' );

		// update can be a single key/value pair or an array of key value pairs

		if ( ! empty ( $_REQUEST['meta_data'] ) ) {
			$customer_meta = isset( $_REQUEST['meta_data'] ) ?  $_REQUEST['meta_data'] : array();
		} elseif ( ! empty( $_REQUEST['meta_key'] ) && isset( $_REQUEST['meta_value'] ) ) {
			$customer_meta = array( $_REQUEST['meta_key'] => $_REQUEST['meta_value'] );
		} else {
			_wpsc_doing_it_wrong( __FUNCTION__, __( 'missing meta key or meta array', 'wpsc' ), '3.8.14' );
			$customer_meta = array();
		}

		// We will want to know which, if any, checkout data changed, so grab the current checkout data so we can compare later
		$old_checkout_info = _wpsc_get_checkout_info();

		// We will also want to keep track of the values that are being set
		$response['changed_customer_meta'] = $customer_meta;

		if ( ! empty( $customer_meta ) ) {

			foreach ( $customer_meta as $meta_key => $meta_value ) {

				// this will echo back any fields to the requester. It's a
				// means for the requester to maintain some state during
				// asynchronous requests

				if ( ! empty( $meta_key ) ) {
					$updated = wpsc_update_customer_meta( $meta_key, $meta_value  );
					$success = $success & $updated;
				}
			}

			// loop through a second time so that all of the meta has been set, tht way if there are
			// dependencies in response calculation
			foreach ( $customer_meta as $meta_key => $meta_value ) {
				$response = apply_filters( 'wpsc_customer_meta_response_' . $meta_key, $response, $meta_key, $meta_value );
			}

			if ( $success ) {
				$response['type']          = __( 'success', 'wpsc' );
				$response['error']         = '';
			} else {
				$response['type']       = __( 'error', 'wpsc' );
				$response['error']      = __( 'meta values may not have been updated', 'wpsc' );
			}
		} else {
				$response['type']       = __( 'error', 'wpsc' );
				$response['error']      = __( 'invalid parameters, meta array or meta key value pair required', 'wpsc' );
		}

		$response = _wpsc_add_customer_meta_to_response( $response );

		foreach ( $response['customer_meta'] as $current_meta_key => $current_meta_value ) {

			// if the meta key and value are the same as what was sent in the request we don't need to
			// send them back because the client already knows about this.
			//
			// But we have to check just in case a data rule or a plugin that used our hooks made some adjustments
			if ( isset( $response['old_customer_meta'][$current_meta_key] ) && ( $response['old_customer_meta'][$current_meta_key] == $current_meta_value ) ) {
				// new value s the same as the old value, why send it?
				unset( $response['customer_meta'][$current_meta_key] );
				unset( $response['old_customer_meta'][$current_meta_key] );
				continue;
			}

			// if the meta value we are considering sending back is one of the values the client gave, we don't send it
			// because the client already knows the meta value and it is probably already visible in the user interface
			if ( isset( $customer_meta[$current_meta_key] ) && ( $customer_meta[$current_meta_key] == $current_meta_value ) ) {
				// new value s the same as the old value, why send it?
				unset( $response['customer_meta'][$current_meta_key] );
				continue;
			}
		}

		// Get the checkout information and if something has changed send it to the client
		$new_checkout_info = _wpsc_wpsc_remove_unchanged_checkout_info( $old_checkout_info, _wpsc_get_checkout_info() );
		if ( ! empty( $new_checkout_info ) ) {
			$response['checkout_info'] = $new_checkout_info;
		} else {
			if ( isset( $response['checkout_info'] ) ) {
				unset( $response['checkout_info'] );
			}
		}

		// We don't need to send the old customer meta values to the client, so we will remove them
		if ( isset( $response['old_customer_meta'] ) ) {
			unset( $response['old_customer_meta'] );
		}

		wp_send_json_success( $response );
	}


	/**
	 * Delete a customer meta
	 * @param string
	 * @return JSON encoded array with results, results include original request parameters
	 * @since 3.8.14
	 */
	function wpsc_delete_customer_meta_ajax() {

		$meta_key = isset( $_POST['meta_key'] ) ?  $_REQUEST['meta_key'] : '';

		$response = array( 'request' => $_REQUEST );

		if ( ! empty( $meta_key ) ) {
			$response['old_value'] = wpsc_get_customer_meta( $meta_key );
			$response['type'] = __( 'success', 'wpsc' );
			$response['error'] = '';
			wpsc_delete_customer_meta( $meta_key );
		} else {
			$response['old_value'] = '';
			$response['type']  = __( 'error', 'wpsc' );
			$response['error'] = __( 'no meta key', 'wpsc' );
			_wpsc_doing_it_wrong( __FUNCTION__, __( 'missing meta key', 'wpsc' ), '3.8.14' );
		}

		$response = _wpsc_add_customer_meta_to_response( $response );
		wp_send_json_success( $response );
	}

	/**
	 * Common routine to put the current customer meta values into an jax
	 * response in a format to be consumed by the wp-e-commerce.js ajax processings
	 *
	 * @since 3.8.14
	 * @access private
	 *
	 * @param array values being readied to send back to javascript in the json encoded AJAX response
	 * @param string|array|null meta keys to retrieve, if not specified all meta keys are retrieved
	 * @return JSON encoded array with results, results include original request parameters
	 */
	function _wpsc_add_customer_meta_to_response( $response, $meta_keys = null, $meta_key = 'customer_meta' ) {

		if ( ! empty( $meta_keys ) ) {
			if ( ! is_array( $meta_keys ) ) {
				$meta_keys = array( $meta_keys );
			}
		} else {
			$meta_keys = wpsc_checkout_unique_names();
		}

		$customer_meta = array();

		foreach ( $meta_keys as $a_meta_key ) {
			$customer_meta[$a_meta_key] = wpsc_get_customer_meta( $a_meta_key );
		}

		$response[$meta_key] = $customer_meta;
		$response = apply_filters( 'wpsc_ajax_response_customer_meta' , $response );

		return $response;
	}

	if ( _wpsc_doing_customer_meta_ajax() ) {
		add_action( 'wp_ajax_wpsc_validate_customer'        , 'wpsc_validate_customer_ajax' );
		add_action( 'wp_ajax_nopriv_wpsc_validate_customer'	, 'wpsc_validate_customer_ajax' );

		add_action( 'wp_ajax_wpsc_get_customer_meta'       	, 'wpsc_get_customer_meta_ajax' );
		add_action( 'wp_ajax_nopriv_wpsc_get_customer_meta'	, 'wpsc_get_customer_meta_ajax' );

		add_action( 'wp_ajax_wpsc_delete_customer_meta'       , 'wpsc_delete_customer_meta_ajax' );
		add_action( 'wp_ajax_nopriv_wpsc_delete_customer_meta', 'wpsc_delete_customer_meta_ajax' );

		add_action( 'wp_ajax_wpsc_update_customer_meta'       , 'wpsc_update_customer_meta_ajax' );
		add_action( 'wp_ajax_nopriv_wpsc_update_customer_meta', 'wpsc_update_customer_meta_ajax' );
	}
} // end if doing customer meta ajax

/*************************************************************************************************
 *  Here start the built in processing that happens when a shopper changes customer meta
 *  on the user facing interface
 *
 *  Note:
 *  the hook priority is set higher than typical user priorities so that other hooks
 *  that want to modify the results are triggered after the built ins
 *
 *  Note:
 *  the update customer meta AJAX routine returns a JSON encoded esponse to the browser. Within
 *  the reponse is the original request, key value pairs for any customer meta items that may need
 *  to be updated in the user interface, and a replacements array that has interfce elements that
 *  may need to be replaced in the user interface.   For an example of the replacements array
 *  element format see _wpsc_shipping_same_as_billing_ajax_response
 *
 *
 *************************************************************************************************/

function _wpsc_customer_shipping_quotes_need_recalc( $meta_value, $meta_key, $customer_id ) {
	wpsc_cart_clear_shipping_info();
}

add_action( 'wpsc_updated_customer_meta_shippinggregion', '_wpsc_customer_shipping_quotes_need_recalc' , 10 , 3 );
add_action( 'wpsc_updated_customer_meta_shippingcountry',  '_wpsc_customer_shipping_quotes_need_recalc' , 10 , 3 );
add_action( 'wpsc_updated_customer_meta_shippingpostcode',  '_wpsc_customer_shipping_quotes_need_recalc' , 10 , 3 );
add_action( 'wpsc_updated_customer_meta_shippingstate', '_wpsc_customer_shipping_quotes_need_recalc' , 10 , 3 );


/***************************************************************************************************************************************
 * Customer meta is built on a lower level API, Visitor meta.  Some visitor meta values are dependant on each other and need to
* be changed when other visitor meta values change.  For example, shipping same as billing.  Below is the built in functionality
* that enforces those changes.  Developers are free to add additional relationships as needed in plugins
***************************************************************************************************************************************/

/**
 * when visitor meta is updated we need to check if the shipping same as billing
 * option is selected.  If so we need to update the corresponding meta value.
 *
 * @since 3.8.14
 * @access private
 * @param $meta_value any value being stored
 * @param $meta_key string name of the attribute being stored
 * @param $visitor_id int id of the visitor to which the attribute applies
* @return n/a
*/
function _wpsc_vistor_shipping_same_as_billing_meta_update( $meta_value, $meta_key, $visitor_id ) {

	// remove the action so we don't cause an infinite loop
	remove_action( 'wpsc_updated_visitor_meta', '_wpsc_vistor_shipping_same_as_billing_meta_update', _WPSC_USER_META_HOOK_PRIORITY );

	// if the shipping same as billing option is being checked then copy meta from billing to shipping
	if ( $meta_key == 'shippingSameBilling' ) {
		if ( $meta_value == 1 ) {

			$checkout_names = wpsc_checkout_unique_names();

			foreach ( $checkout_names as $meta_key ) {
				$meta_key_starts_with_billing = strpos( $meta_key, 'billing', 0 ) === 0;

				if ( $meta_key_starts_with_billing ) {
					$other_meta_key_name = 'shipping' . substr( $meta_key, strlen( 'billing' ) );
					if ( in_array( $other_meta_key_name, $checkout_names ) ) {
						$billing_meta_value = wpsc_get_customer_meta( $meta_key );
						wpsc_update_customer_meta( $other_meta_key_name, $billing_meta_value );
					}
				}
			}
		}
	} else {
		$shipping_same_as_billing = wpsc_get_customer_meta( 'shippingSameBilling' );

		if ( $shipping_same_as_billing ) {

			$meta_key_starts_with_billing = strpos( $meta_key, 'billing', 0 ) === 0;
			$meta_key_starts_with_shipping = strpos( $meta_key, 'shipping', 0 ) === 0;

			if ( $meta_key_starts_with_billing ) {
				$checkout_names = wpsc_checkout_unique_names();

				$other_meta_key_name = 'shipping' . substr( $meta_key, strlen( 'billing' ) );

				if ( in_array( $other_meta_key_name, $checkout_names ) ) {
					wpsc_update_customer_meta( $other_meta_key_name, $meta_value );
				}
			} elseif ( $meta_key_starts_with_shipping ) {
				$checkout_names = wpsc_checkout_unique_names();

				$other_meta_key_name = 'billing' . substr( $meta_key, strlen( 'shipping' ) );

				if ( in_array( $other_meta_key_name, $checkout_names ) ) {
					wpsc_update_customer_meta( $other_meta_key_name, $meta_value );
				}
			}
		}
	}

	// restore the action we removed at the start
	add_action( 'wpsc_updated_visitor_meta', '_wpsc_vistor_shipping_same_as_billing_meta_update', _WPSC_USER_META_HOOK_PRIORITY, 3 );
}

add_action( 'wpsc_updated_visitor_meta', '_wpsc_vistor_shipping_same_as_billing_meta_update', _WPSC_USER_META_HOOK_PRIORITY, 3 );




/**
 * Get replacement elements for country and region fields on the checkout form
 *
 * @since 3.8.14
 * @access private
 * @param array $replacements
 * @return array $replacements array
 */
function _wpsc_get_country_and_region_replacements( $replacements = null, $replacebilling = true, $replaceshipping = true ) {
	global $wpsc_checkout;
	if ( empty( $wpsc_checkout ) ) {
		$wpsc_checkout = new wpsc_checkout();
	}

	if ( empty( $replacements ) ) {
		$replacements = array();
	}

	while ( wpsc_have_checkout_items() ) {
		$checkoutitem = wpsc_the_checkout_item();

		if ( $replaceshipping && ( $checkoutitem->unique_name == 'shippingcountry' ) ) {
			$element_id = 'region_country_form_' . wpsc_checkout_form_item_id();
			$replacement = array( 'elementid' => $element_id, 'element' => wpsc_checkout_form_field() );
			$replacements['shippingcountry'] = $replacement;
		}

		if ( $replaceshipping && ( $checkoutitem->unique_name == 'shippingstate' ) ) {
			$element_id = wpsc_checkout_form_element_id();
			$replacement = array( 'elementid' => $element_id, 'element' => wpsc_checkout_form_field() );
			$replacements['shippingstate'] = $replacement;
		}

		if ( $replacebilling && ( $checkoutitem->unique_name == 'billingcountry' ) ) {
			$element_id = 'region_country_form_' . wpsc_checkout_form_item_id();
			$replacement = array( 'elementid' => $element_id, 'element' => wpsc_checkout_form_field() );
			$replacements['billingcountry'] = $replacement;
		}

		if ( $replacebilling && ( $checkoutitem->unique_name == 'billingstate' ) ) {
			$element_id = wpsc_checkout_form_item_id();
			$replacement = array( 'elementid' => $element_id, 'element' => wpsc_checkout_form_field() );
			$replacements['billingstate'] = $replacement;
		}
	}

	return $replacements;
}

/**
 * Get replacement elements for country and region fields on the checkout form
 *
 *  Note: extracted from the wpsc_change_tax function in ajax.php as of version 3.8.13.3
 *
 * @since 3.8.14
 * @access private
 * @return array  checkout information
 */
function _wpsc_get_checkout_info() {
	global $wpsc_cart;

	// Checkout info is what we will return to the AJAX client
	$checkout_info = array();

	// start with items that have no dependencies

	$checkout_info['delivery_country'] = wpsc_get_customer_meta( 'shippingcountry' );
	$checkout_info['billing_country']  = wpsc_get_customer_meta( 'billingcountry' );
	$checkout_info['country_name']     = wpsc_get_country( $checkout_info['delivery_country'] );
	$checkout_info['lock_tax']         = get_option( 'lock_tax' );  // TODO: this is set anywhere, probably deprecated

	$checkout_info['needs_shipping_recalc'] = wpsc_cart_need_to_recompute_shipping_quotes();
	$checkout_info['shipping_keys']         = array();

	foreach ( $wpsc_cart->cart_items as $key => $cart_item ) {
		$checkout_info['shipping_keys'][ $key ] = wpsc_currency_display( $cart_item->shipping );
	}

	if ( ! $checkout_info['needs_shipping_recalc'] ) {

		$wpsc_cart->update_location();
		$wpsc_cart->get_shipping_method();
		$wpsc_cart->get_shipping_option();

		if ( $wpsc_cart->selected_shipping_method != '' ) {
			$wpsc_cart->update_shipping( $wpsc_cart->selected_shipping_method, $wpsc_cart->selected_shipping_option );
		}

		$tax         = $wpsc_cart->calculate_total_tax();
		$total       = wpsc_cart_total();
		$total_input = wpsc_cart_total( false );

		if ( $wpsc_cart->coupons_amount >= $total_input && ! empty( $wpsc_cart->coupons_amount ) ) {
			$total = 0;
		}

		if ( $wpsc_cart->total_price < 0 ) {
			$wpsc_cart->coupons_amount += $wpsc_cart->total_price;
			$wpsc_cart->total_price     = null;
			$wpsc_cart->calculate_total_price();
		}

		$cart_widget = _wpsc_ajax_get_cart( false );

		if ( isset( $cart_widget['widget_output'] ) && ! empty ( $cart_widget['widget_output'] ) ) {
			$checkout_info['widget_output'] = $cart_widget['widget_output'];
		}

		$checkout_info['cart_shipping'] = wpsc_cart_shipping();
		$checkout_info['tax']           = $tax;
		$checkout_info['display_tax']   = wpsc_cart_tax();
		$checkout_info['total']         = $total;
		$checkout_info['total_input']   = $total_input;
	}

	return apply_filters( 'wpsc_ajax_checkout_info', $checkout_info );;
}


/**
 * remove checkout info that has not changed
 *
 * @since 3.8.14
 * @access private
 * @return array  checkout information
 */
function _wpsc_remove_unchanged_checkout_info( $old_checkout_info, $new_checkout_info ) {

	foreach ( $new_checkout_info as $key => $value ) {
		if ( isset( $old_checkout_info[ $key ] ) ) {
			$old_checkout_info_crc = crc32( json_encode( $old_checkout_info[ $key ] ) );
			$new_checkout_info_crc = crc32( json_encode( $value ) );

			if ( $old_checkout_info_crc == $new_checkout_info_crc ) {
				unset( $new_checkout_info[ $key ] );
			}
		}
	}

	return $new_checkout_info;
}


