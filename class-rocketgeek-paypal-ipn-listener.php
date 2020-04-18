<?php
/**
 * The RocketGeek_IPN_Listener PayPal IPN Listener Class.
 *
 * This class handles processing PayPal IPN payments.
 * Uses WP's wp_remote_post() by default,
 * or, if set, cURL (legacy).
 *
 * @package WordPress
 * @subpackage RocketGeek_PayPal_IPN_Listener
 */
class RocketGeek_PayPal_IPN_Listener {

	/**
	 * Container for data to be passed to PayPal for validation.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private $encoded_data;

	/**
	 * Result returned from PayPal.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string
	 */
	private $curl_result;
	
	/**
	 * Container for errors.
	 *
	 * @since 1.0.0
	 * @access public
	 * @var bool|array
	 */
	public $error = array();
	
	/**
	 * Container for transaction notes.
	 *
	 * @since 1.0.0
	 * @access public
	 * @var string
	 */
	public $notes = '';
	
	/**
	 * Transaction table name.
	 *
	 * @since 1.0.0
	 * @access public
	 * @var string
	 */
	public $transaction_table = 'rgipn_paypal_transactions';
	
	/**
	 * IPN message log table.
	 *
	 * @since 1.0.0
	 * @access public
	 * @var string
	 */
	public $log_table = 'rgipn_paypal_ipn_messages';

	/**
	 * PayPal URL container.
	 *
	 * @since 1.0.0
	 * @access public
	 * @var string
	 */
	public $paypal_url;
	
	/** 
	 * The class contructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct( $args ) {

		
	}

	/**
	 * IPN Listener function.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @global stdClass $wpdb
	 */
	public function do_ipn() {

		global $wpdb;

		/**
		 * Fires at the beginning of IPN processing.
		 *
		 * @since 1.0.0
		 */
		do_action( 'rg_ipn_start' );		

		// Verify response.
		$response = ( 1 == $this->use_curl ) ? $this->verify_ipn_with_curl() : $this->verify_ipn_with_remote_post();
		
		// Assign PayPal's posted variables to local array.
		$details = array();
		foreach ( $_POST as $key => $val ) {
			// Don't forget to sanitize!!
			$details[ $key ] = sanitize_text_field( $val );
		}

		// User ID is passed through "custom"
		$user_id = ( isset( $details['custom'] ) ) ? $details['custom'] : 0;
		
		// Inspect IPN validation result and act accordingly.
		// If the result was "VERIFIED" continue to process.
		if ( "VERIFIED" == $response ) {

			/**
			 * Fire the IPN validation action.
			 *
			 * @since 1.0.0
			 *
			 * @param array $details The IPN message details.
			 */
			do_action( 'rg_ipn_validation', $details );

			// If no errors in validation, we are OK to process.
			if ( ! $this->error ) {

				/**
				 * Fires after successful IPN process.
				 *
				 * @since 1.0.0
				 *
				 * @param  int     $user_id      The user's numeric WP ID.
				 * @param  array   $details      The PayPal transaction details.
				 * @param  string  'success'     Identifies the action.
				 * @param  string  $this->notes  A container for logging notes
				 */
				do_action( 'rg_ipn_success', $user_id, $details, 'success', $this->notes );
				
			} else {
				
				/**
				 * Fires after failed transaction.
				 *
				 * @since 1.0.0
				 *
				 * @param  int     $user_id      The user's numeric WP ID.
				 * @param  array   $details      The PayPal transaction details.
				 * @param  string  'error'       Identifies the action.
				 * @param  string  $this->error
				 */
				do_action( 'rg_ipn_error', $user_id, $details, 'error', $this->error );
				
			}

		} else {

			/**
			 * Fires after invalid IPN process.
			 *
			 * @since 1.0.0
			 *
			 * @param  int     $user_id      The user's numeric WP ID.
			 * @param  array   $details      The PayPal transaction details.
			 * @param  string  'invalid'     Identifies the action.
			 * @param  string  $this->notes  A container for logging notes.
			 */
			do_action( 'rg_ipn_invalid', $user_id, $details, 'invalid', $this->notes );
		}

	}

	/**
	 * Records the transaction data.
	 *
	 * @since 1.0.0
	 *
	 * @global  stdClass  $wpdb
	 * @param   array     $details
	 */
	private function record_transaction( $details ) {

		global $wpdb;

		$sql_cols = "user_id, timestamp, ";
		$sql_vals = "'" . $details['custom'] . "','" . current_time( 'mysql' ) . "',";
		$txn_cols = $this->record_transaction_columns();
		foreach ( $txn_cols as $column ) {
			if ( isset( $details[ $column ] ) ) {
				$sql_cols.= $column . ",";
				$sql_vals.= "'" . $details[ $column ] . "',";
			}
		}
		$sql = "INSERT INTO " . $wpdb->prefix . $this->transaction_table . " ( " . rtrim( $sql_cols, ',' ) . " ) VALUES ( " . rtrim( $sql_vals, ',' ) . " )";
		
		$result = $wpdb->query( $sql );
		
		return;
	}
	
	/**
	 * Logs IPN messages in db.
	 *
	 * @since 1.0.0
	 *
	 * @global object $wpdb
	 * @param  int    $user_id
	 * @param  string $details
	 * @param  string $result
	 * @param  string $notes
	 */
	public function ipn_db_log( $user_id, $details, $result, $notes ) {
		global $wpdb;
		$status = ( isset( $details['payment_status'] ) ) ? $details['payment_status'] : '';
		$reason = ( isset( $details['pending_reason'] ) ) ? $details['pending_reason'] : '';
		$txn_id = ( isset( $details['txn_id']         ) ) ? $details['txn_id']         : '';
		$data   = http_build_query( $details );
		$sql = "INSERT INTO " . $wpdb->prefix . $this->log_table . " ( user_id, timestamp, txn_id, result, payment_status, pending_reason, ipn_detail, notes ) VALUES ( " 
			. $user_id . ','
			. '"' . date( 'Y-m-d H:i:s', time() ) . '",'
			. '"' . $txn_id . '",'
			. '"' . $action . '",'
			. '"' . $status . '",'
			. '"' . $reason . '",'
			. '"' . $data . '",'
			. '"' . $notes . '"'
			. " )";
		$result = $wpdb->query( $sql );
	}
	
	/**
	 * Possible columns for transaction recording.
	 *
	 * @since 1.0.0
	 *
	 * @return array $columns
	 */
	private function record_transaction_columns() {
		$columns = array( 
			'payment_date',
			'receiver_email',
			'item_name',
			'item_number',
			'payment_status',
			'pending_reason',
			'mc_gross',
			'mc_fee',
			'tax',
			'mc_currency',
			'txn_id',
			'txn_type',
			'transaction_subject',
			'first_name',
			'last_name',
			'address_name',
			'address_street',
			'address_city',
			'address_state',
			'address_zip',
			'address_country',
			'address_country_code',
			'residence_country',
			'address_status',
			'payer_email',
			'payer_status',
			'payment_type',
			'payment_gross',
			'payment_fee',
			'notify_version',
			'verify_sign',
			'referrer_id',
			'business',
			'ipn_track_id',
		);
		return $columns;
	}

	/**
	 * Reads IPN data and validates using WP's remote_post (default).
	 *
	 * @since 1.0.0
	 *
	 * @global string   $wp_version
	 * @return string   VERIFIED or INVALID
	 */
	private function verify_ipn_with_remote_post() {
		
		global $wp_version;
		
		// Get recieved values from post data
		$ipn_data = (array) stripslashes_deep( $_POST );
		$ipn_data['cmd'] = '_notify-validate';

		// Send back post vars to paypal
		$params = array(
			'body' => $ipn_data,
			'sslverify' => false,
			'timeout' => 30,
			'user-agent' => 'WordPress/' . $wp_version . '; ' . home_url( '/' ),
		);

		/**
		 * Filter the parameters for IPN response.
		 *
		 * @since 1.0.0
		 *
		 * @param  array  $params
		 */
		$params = apply_filters( 'rg_ipn_remote_post_response_params', $params );
		$response = wp_remote_post( $this->paypal_url, $params );
		
		return ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && ( strcmp( $response['body'], "VERIFIED" ) == 0 ) ) ? "VERIFIED" : "INVALID";
	}
	
	/**
	 * Reads IPN data and validates using cURL.
	 *
	 * @since 1.0.0
	 *
	 * @return string   VERIFIED or INVALID
	 */
	private function verify_ipn_with_curl() {
				
		// Read IPN post data.
		$raw_post_data  = file_get_contents( 'php://input' );
		$raw_post_array = explode( '&', $raw_post_data );
		$post_array     = array();

		// Assemble raw post data into array.
		foreach ( $raw_post_array as $keyval ) {
			$keyval = explode ( '=', $keyval );
			if ( 2 == count( $keyval ) )
				$post_array[ $keyval[0] ] = urldecode( $keyval[1] );
		}

		// Prepare validation return response.
		$encoded_data = 'cmd=_notify-validate';

		// Assemble validation return response.
		foreach ( $post_array as $key => $value ) {        
			if ( function_exists( 'get_magic_quotes_gpc' ) && 1 == get_magic_quotes_gpc() ) { 
				$value = urlencode( stripslashes( $value ) ); 
			} else {
				$value = urlencode( $value );
			}
			$encoded_data .= "&$key=$value";
		}

		/**
		 * Filter the cURL IPN response.
		 *
		 * @since 1.0.0
		 *
		 * @param string $endoded_data
		 */
		$encoded_data = apply_filters( 'rg_ipn_curl_response_params', $encoded_data );
		// Post IPN data back to paypal to validate (requires CURL!).

		$defaults = array(
			'CURLOPT_HTTP_VERSION'   => 'CURL_HTTP_VERSION_1_1',
			'CURLOPT_POST'           => '1',
			'CURLOPT_RETURNTRANSFER' => '1',
			'CURLOPT_POSTFIELDS'     => $encoded_data,
			'CURLOPT_SSL_VERIFYPEER' => '1',
			'CURLOPT_SSL_VERIFYHOST' => '2',
			'CURLOPT_FORBID_REUSE'   => '1',
			'CURLOPT_HTTPHEADER'     => array( 'Connection: Close' ),
		);
		/**
		 * Filter settings for cURL options.
		 *
		 * @since 1.0.0
		 */
		$args = apply_filters( 'rg_ipn_curl_options', $defaults );
		
		$ch = curl_init( $this->paypal_url );
		curl_setopt( $ch, CURLOPT_HTTP_VERSION,   $args['CURLOPT_HTTP_VERSION'] );
		curl_setopt( $ch, CURLOPT_POST,           $args['CURLOPT_POST'] );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, $args['CURLOPT_RETURNTRANSFER'] );
		curl_setopt( $ch, CURLOPT_POSTFIELDS,     $args['CURLOPT_POSTFIELDS'] );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $args['CURLOPT_SSL_VERIFYPEER'] );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $args['CURLOPT_SSL_VERIFYHOST'] );
		curl_setopt( $ch, CURLOPT_FORBID_REUSE,   $args['CURLOPT_FORBID_REUSE'] );
		curl_setopt( $ch, CURLOPT_HTTPHEADER,     $args['CURLOPT_HTTPHEADER'] );
		if ( isset( $args['CURLOPT_SSLVERSION'] ) ) {
			curl_setopt( $ch, CURLOPT_SSLVERSION, $args['CURLOPT_HTTPHEADER'] );
		}
		if ( isset( $args['CURLOPT_SSL_CIPHER_LIST'] ) ) {
			curl_setopt( $ch, CURLOPT_SSL_CIPHER_LIST, $args['CURLOPT_SSL_CIPHER_LIST'] );
		}
	
		$curl_result = curl_exec( $ch );
		
		// If result is an error, log and kill processing.
		if ( false === $curl_result ) {
			/**
			 * Fires if there was a curl error.
			 *
			 * @since 1.0.0
			 */
			do_action( 'rg_cur_error', curl_error( $ch ) );
			die( curl_error( $ch ) );
		}
	
		// Done with curl.
		curl_close( $ch );

		// Inspect IPN validation result and act accordingly.
		// If the result was "VERIFIED" continue to process.
		return ( 0 == strcmp( $curl_result, "VERIFIED" ) ) ? "VERIFIED" : "INVALID";
	}

	/**
	 * Create IPN messages table
	 *
	 * @since 1.0.0
	 *
	 * @global object $wpdb
	 */
	public function create_ipn_messages_table() {
		global $wpdb;
		// Insert log table.
		$create_ipn_messages_table = "CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . $this->log_table . " (
			id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT(20) NOT NULL,
			timestamp DATETIME NOT NULL,
			txn_id           VARCHAR(30),
			result           VARCHAR(30),
			payment_status   VARCHAR(30),
			pending_reason   VARCHAR(30),
			ipn_detail       LONGTEXT,
			notes            VARCHAR(80)
		)";
		$wpdb->query( $create_ipn_messages_table );
	}

	/**
	 * Create transaction table.
	 *
	 * @since 1.0.0
	 *
	 * @global object $wpdb
	 */
	public function create_transaction_table() {
		global $wpdb;
		// Install the transaction table.
		$create_transaction_table = "CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . $this->transaction_table . " (
			id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT(20) NOT NULL,
			timestamp DATETIME NOT NULL ,
			payment_date         VARCHAR(30),
			receiver_email       VARCHAR(60),
			item_name            VARCHAR(100),
			item_number          VARCHAR(10),
			payment_status       VARCHAR(10),
			pending_reason       VARCHAR(10),
			mc_gross             VARCHAR(20),
			mc_fee               VARCHAR(20),
			tax                  VARCHAR(20),
			mc_currency          VARCHAR(10),
			txn_id               VARCHAR(30),
			txn_type             VARCHAR(10),
			transaction_subject  VARCHAR(50),
			first_name           VARCHAR(30),
			last_name            VARCHAR(40),
			address_name         VARCHAR(50),
			address_street       VARCHAR(50),
			address_city         VARCHAR(30),
			address_state        VARCHAR(30),
			address_zip          VARCHAR(20),
			address_country      VARCHAR(30),
			address_country_code VARCHAR(10),
			residence_country    VARCHAR(10),
			address_status       VARCHAR(10),
			payer_email          VARCHAR(60),
			payer_status         VARCHAR(10),
			payment_type         VARCHAR(10),
			payment_gross        VARCHAR(20),
			payment_fee          VARCHAR(20),
			notify_version       VARCHAR(10),
			verify_sign          VARCHAR(10),
			referrer_id          VARCHAR(10),
			business             VARCHAR(60),
			ipn_track_id         VARCHAR(20)
		)";
		$wpdb->query( $create_transaction_table );
	}

}
