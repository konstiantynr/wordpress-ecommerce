<?php
/**
 * The PayPal Pro Gateway class
 *
 */

class WPSC_Payment_Gateway_Paypal_Pro extends WPSC_Payment_Gateway {	
	private $gateway;

	/**
	 * Constructor of PayPal Pro Gateway
	 *
	 * @param array $options
	 * @return void
	 *
	 * @since 3.9
	 */
	public function __construct( $options ) {
		parent::__construct();

		$this->title = __( 'PayPal Pro 3.0', 'wpsc' );

		require_once( 'php-merchant/gateways/paypal-pro.php' );

		$this->gateway = new PHP_Merchant_Paypal_Pro( $options );

		$this->gateway->set_options( array(
			'api_username'     => $this->setting->get( 'api_username' ),
			'api_password'     => $this->setting->get( 'api_password' ),
			'api_signature'    => $this->setting->get( 'api_signature' ),
			'cancel_url'       => $this->get_shopping_cart_payment_url(),
			'currency'         => $this->get_currency_code(),
			'test'             => (bool) $this->setting->get( 'sandbox_mode' ),
			// 'address_override' => 1,
			// 'solution_type'    => 'mark',
			// 'cart_logo'        => $this->setting->get( 'cart_logo' ),
			// 'cart_border'      => $this->setting->get( 'cart_border' ),
		) );

		add_filter( 'wpsc_purchase_log_gateway_data', array( $this, 'filter_purchase_log_gateway_data' ), 10, 2 );

		// Unselect Default Payment Gateway
		add_filter(
			'wpsc_payment_method_form_fields',
			array( $this, 'filter_unselect_default' ), 100 , 1 
		);
	}

	/**
	 * No payment gateway is selected by default
	 *
	 * @access public 
	 * @param array $fields
	 * @return array
	 *
	 * @since 3.9
	 */
	public function filter_unselect_default( $fields ) {
		$fields[0]['checked'] = false;
		return $fields;
	}

	/**
	 * Returns the PayPal redirect URL
	 *
	 * @param array $data Arguments to encode with the URL
	 * @return string
	 *
	 * @since 3.9
	 */
	public function get_redirect_url( $data = array() ) {

	}

	/**
	 * Purchase Log Filter for Gateway Data
	 *
	 * @param array $gateway_data
	 * @param array $data
	 * @return array
	 *
	 * @since 3.9
	 */
	public static function filter_purchase_log_gateway_data( $gateway_data, $data ) {
		// Because paypal express checkout API doesn't have full support for discount, we have to manually add an item here
		if ( isset( $gateway_data['discount'] ) && (float) $gateway_data['discount'] != 0 ) {
			$i =& $gateway_data['items'];
			$d =& $gateway_data['discount'];
			$s =& $gateway_data['subtotal'];

			// If discount amount is larger than or equal to the item total, we need to set item total to 0.01
			// because Paypal does not accept 0 item total.
			if ( $d >= $gateway_data['subtotal'] ) {
				$d = $s - 0.01;

				// if there's shipping, we'll take 0.01 from there
				if ( ! empty( $gateway_data['shipping'] ) ) {
					$gateway_data['shipping'] -= 0.01;
				} else {
					$gateway_data['amount'] = 0.01;
				}
			}

			$s -= $d;

			$i[] = array(
				'name'     => __( 'Discount', 'wpsc' ),
				'amount'   => - $d,
				'quantity' => 1,
			);
		}
		return $gateway_data;
	}

	/**
	 * Returns the URL of the Return Page after the PayPal Checkout
	 *
	 * @return string
	 */
	protected function get_return_url() {

	}

	/**
	 * Returns the URL of the IPN Page
	 *
	 * @return string
	 */
	protected function get_notify_url() {
		$location = add_query_arg( array(
			'payment_gateway'          => 'paypal-pro',
			'payment_gateway_callback' => 'ipn',
		), home_url( 'index.php' ) );

		return apply_filters( 'wpsc_paypal_pro_notify_url', $location );
	}

	/**
	 * Creates a new Purchase Log entry and set it to the current object
	 *
	 * @return null
	 */
	protected function set_purchase_log_for_callbacks( $sessionid = false ) {
		// Define the sessionid if it's not passed
		if ( $sessionid === false ) {
			$sessionid = $_REQUEST['sessionid'];
		}

		// Create a new Purchase Log entry
		$purchase_log = new WPSC_Purchase_Log( $sessionid, 'sessionid' );

		if ( ! $purchase_log->exists() ) {
			return null;
		}

		// Set the Purchase Log for the gateway object
		$this->set_purchase_log( $purchase_log );
	}

	/**
	 * IPN Callback function
	 *
	 * @return void
	 */
	public function callback_ipn() {

	}

	/**
	 * Confirm Transaction Callback
	 *
	 * @return bool
	 *
	 * @since 3.9
	 */
	public function callback_confirm_transaction() {

	}

	/**
	 * Process the transaction through the PayPal APIs
	 *
	 * @since 3.9
	 */
	public function do_transaction() {

	}

	public function callback_display_paypal_error() {
		add_filter( 'wpsc_get_transaction_html_output', array( $this, 'filter_paypal_error_page' ) );
	}

	public function callback_display_generic_error() {
		add_filter( 'wpsc_get_transaction_html_output', array( $this, 'filter_generic_error_page' ) );
	}

	/**
	 * Records the Payer ID, Payer Status and Shipping Status to the Purchase
	 * Log on GetExpressCheckout Call
	 *
	 * @return void
	 */
	public function log_payer_details( $details ) {
		if ( isset( $details->get( 'payer' )->id ) && !empty( $details->get( 'payer' )->id ) ) {
			$payer_id = $details->get( 'payer' )->id;
		} else {
			$payer_id = 'not set';
		}
		if ( isset( $details->get( 'payer' )->status ) && !empty( $details->get( 'payer' )->status ) ) {
			$payer_status = $details->get( 'payer' )->status;
		} else {
			$payer_status = 'not set';
		}
		if ( isset( $details->get( 'payer' )->shipping_status ) && !empty( $details->get( 'payer' )->shipping_status ) ) {
			$payer_shipping_status = $details->get( 'payer' )->shipping_status;
		} else {
			$payer_shipping_status = 'not set';
		}
		$paypal_log = array(
			'payer_id'        => $payer_id,
			'payer_status'    => $payer_status,
			'shipping_status' => $payer_shipping_status,
			'protection'      => null,
		);

		wpsc_update_purchase_meta( $this->purchase_log->get( 'id' ), 'paypal_ec_details' , $paypal_log );
	}

	/**
	 * Records the Protection Eligibility status to the Purchase Log on
	 * DoExpressCheckout Call
	 *
	 * @return void
	 */
	public function log_protection_status( $response ) {
		$params = $response->get_params();

		$elg                      = $params['PAYMENTINFO_0_PROTECTIONELIGIBILITY'];
		$paypal_log               = wpsc_get_purchase_meta( $this->purchase_log->get( 'id' ), 'paypal_ec_details', true );
		$paypal_log['protection'] = $elg;
		wpsc_update_purchase_meta( $this->purchase_log->get( 'id' ), 'paypal_ec_details' , $paypal_log );
	}

	public function callback_process_confirmed_payment() {

	}

	/**
	 * Error Page Template
	 *
	 * @since 3.9
	 */
	public function filter_paypal_error_page() {
		$errors = wpsc_get_customer_meta( 'paypal_pro_errors' );
		ob_start();
?>
		<p>
			<?php _e( 'Sorry, your transaction could not be processed by PayPal. Please contact the site administrator. The following errors are returned:' , 'wpsc' ); ?>
		</p>
		<ul>
			<?php foreach ( $errors as $error ): ?>
				<li><?php echo esc_html( $error['details'] ) ?> (<?php echo esc_html( $error['code'] ); ?>)</li>
			<?php endforeach; ?>
		</ul>
		<p><a href="<?php echo esc_url( $this->get_shopping_cart_payment_url() ); ?>"><?php ( 'Click here to go back to the checkout page.') ?></a></p>
<?php
		$output = apply_filters( 'wpsc_paypal_pro_gateway_error_message', ob_get_clean(), $errors );
		return $output;
	}

	/**
	 * Generic Error Page Template
	 *
	 * @since 3.9
	 */
	public function filter_generic_error_page() {
		ob_start();
?>
			<p><?php _e( 'Sorry, but your transaction could not be processed by PayPal for some reason. Please contact the site administrator.' , 'wpsc' ); ?></p>
			<p><a href="<?php echo esc_attr( $this->get_shopping_cart_payment_url() ); ?>"><?php _e( 'Click here to go back to the checkout page.', 'wpsc' ) ?></a></p>
<?php
		$output = apply_filters( 'wpsc_paypal_pro_generic_error_message', ob_get_clean() );
		return $output;
	}

	/**
	 * Settings Form Template
	 *
	 * @since 3.9
	 */
	public function setup_form() {
		$paypal_currency = $this->get_currency_code();
?>

		<!-- Account Credentials -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Account Credentials', 'wpsc' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-pro-api-username"><?php _e( 'API Username', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_username' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_username' ) ); ?>" id="wpsc-paypal-pro-api-username" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-pro-api-password"><?php _e( 'API Password', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_password' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_password' ) ); ?>" id="wpsc-paypal-pro-api-password" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-pro-api-signature"><?php _e( 'API Signature', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_signature' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_signature' ) ); ?>" id="wpsc-paypal-pro-api-signature" />
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Sandbox Mode', 'wpsc' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'sandbox_mode' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'sandbox_mode' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'IPN', 'wpsc' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'ipn' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'ipn' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'ipn' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'ipn' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
			</td>
		</tr>

		<!-- Currency Conversion -->
		<?php if ( ! $this->is_currency_supported() ): ?>
			<tr>
				<td colspan="2">
					<h4><?php _e( 'Currency Conversion', 'wpsc' ); ?></h4>
				</td>
			</tr>
			<tr>
				<td colspan="2">
					<p><?php _e( 'Your base currency is currently not accepted by PayPal. As a result, before a payment request is sent to PayPal, WP eCommerce has to convert the amounts into one of PayPal supported currencies. Please select your preferred currency below.', 'wpsc' ); ?></p>
				</td>
			</tr>
			<tr>
				<td>
					<label for "wpsc-paypal-pro-currency"><?php _e( 'PayPal Currency', 'wpsc' ); ?></label>
				</td>
				<td>
					<select name="<?php echo esc_attr( $this->setting->get_field_name( 'currency' ) ); ?>" id="wpsc-paypal-pro-currency">
						<?php foreach ( $this->gateway->get_supported_currencies() as $currency ) : ?>
							<option <?php selected( $currency, $paypal_currency ); ?> value="<?php echo esc_attr( $currency ); ?>"><?php echo esc_html( $currency ); ?></option>
						<?php endforeach ?>
					</select>
				</td>
			</tr>
		<?php endif ?>

		<!-- Error Logging -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Error Logging', 'wpsc' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Enable Debugging', 'wpsc' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'debugging' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'debugging' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
			</td>
		</tr>
<?php
	}

	/**
	 * Check if the selected currency is supported by the gateway
	 *
	 * @return bool
	 *
	 * @since 3.9
	 */
	protected function is_currency_supported() {
		return in_array( parent::get_currency_code(), $this->gateway->get_supported_currencies() );
	}

	/**
	 * Return the Currency ISO code
	 *
	 * @return string
	 *
	 * @since 3.9
	 */
	public function get_currency_code() {
		$code = parent::get_currency_code();

		if ( ! in_array( $code, $this->gateway->get_supported_currencies() ) ) {
			$code = $this->setting->get( 'currency', 'USD' );
		}

		return $code;
	}

	/**
	 * Convert an amount (integer) to the supported currency
	 * @param integer $amt
	 *
	 * @return integer
	 *
	 * @since 3.9
	 */
	protected function convert( $amt ) {
		if ( $this->is_currency_supported() ) {
			return $amt;
		}

		return wpsc_convert_currency( $amt, parent::get_currency_code(), $this->get_currency_code() );
	}

	/**
	 * Process the SetExpressCheckout API Call
	 *
	 * @return void
	 *
	 * @since 3.9
	 */
	public function process() {

	}

	/**
	 * Log an error message
	 *
	 * @param PHP_Merchant_Paypal_Express_Checkout_Response $response
	 * @return void
	 *
	 * @since 3.9
	 */
	public function log_error( $response ) {
		if ( $this->setting->get( 'debugging' ) ) {
			$log_data = array(
				'post_title'    => 'PayPal Pro Operation Failure',
				'post_content'  =>  'There was an error processing the payment. Find details in the log entry meta fields.',
				'log_type'      => 'error'
			);

			$log_meta = array(
				'correlation_id'   => $response->get( 'correlation_id' ),
				'time' => $response->get( 'datetime' ),
				'errors' => $response->get_errors(),
			);

			$log_entry = WPSC_Logging::insert_log( $log_data, $log_meta );
		}
	}
}
