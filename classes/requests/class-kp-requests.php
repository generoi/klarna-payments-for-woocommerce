<?php
/**
 * Main request class
 *
 * @package WC_Klarna_Payments/Classes/Requests
 */

defined( 'ABSPATH' ) || exit;

use Krokedil\WpApi\Request;

/**
 * Base class for all request classes.
 */
abstract class KP_Requests extends Request {
	/**
	 * The Klarna merchant Id, or MID. Used for calculating the request auth.
	 *
	 * @var string
	 */
	protected $merchant_id;

	/**
	 * The Klarna shared api secret. Used for calculating the request auth.
	 *
	 * @var string
	 */
	protected $shared_secret;

	/**
	 * Iframe options generated by helper class used to modify what the Klarna Payments iframe looks like.
	 *
	 * @var KP_IFrame
	 */
	protected $iframe_options;

	/**
	 * Filter to use for the request args.
	 *
	 * @var string
	 */
	protected $request_filter = 'wc_klarna_payments_request_args';

	/**
	 * Class constructor.
	 *
	 * @param mixed $arguments The request arguments.
	 */
	public function __construct( $arguments ) {
		$settings = get_option( 'woocommerce_klarna_payments_settings' );
		$config   = array(
			'slug'                   => 'klarna_payments',
			'plugin_version'         => WC_KLARNA_PAYMENTS_VERSION,
			'plugin_short_name'      => 'KP',
			'plugin_user_agent_name' => 'KP',
			'logging_enabled'        => 'yes' === $settings['logging'],
			'extended_debugging'     => 'no' === $settings['extra_logging'],
			'base_url'               => $this->get_base_url( $arguments['country'], $settings ),
		);

		parent::__construct( $config, $settings, $arguments );
		$this->set_credentials();
		$this->iframe_options = new KP_IFrame( $settings );

		add_filter( 'wc_kp_image_url_cart_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
		add_filter( 'wc_kp_url_cart_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
		add_filter( 'wc_kp_image_url_order_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
		add_filter( 'wc_kp_url_order_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
	}

	/**
	 * Sets the environment.
	 *
	 * @param string $country The country code.
	 * @param array  $settings The settings array.
	 */
	protected function get_base_url( $country, $settings ) {
		$country_data = KP_Form_Fields::$kp_form_auto_countries[ strtolower( $country ?? '' ) ] ?? null;

		$region     = $country_data['endpoint'] ?? ''; // Get the region from the country parameters, blank for EU.
		$playground = 'yes' === $settings['testmode'] ? 'playground' : ''; // If testmode is enabled, add playground to the subdomain.
		$subdomain    = "api{$region}.{$playground}"; // Combine the string to one subdomain.

		return "https://{$subdomain}.klarna.com/"; // Return the full base url for the api.
	}

	/**
	 * Sets Klarna credentials.
	 */
	public function set_credentials() {
		$prefix = 'yes' === $this->settings['testmode'] ? 'test_' : ''; // If testmode is enabled, add test_ to the setting strings.
		$suffix = '_' . strtolower( $this->arguments['country'] ) ?? strtolower( kp_get_klarna_country() ); // Get the country from the arguments, or the fetch from helper method.

		$merchant_id   = "{$prefix}merchant_id{$suffix}";
		$shared_secret = "{$prefix}shared_secret{$suffix}";

		$this->merchant_id   = isset( $this->settings[ $merchant_id ] ) ? $this->settings[ $merchant_id ] : '';
		$this->shared_secret = isset( $this->settings[ $shared_secret ] ) ? $this->settings[ $shared_secret ] : '';
	}

	public function calculate_auth() {
		return 'Basic ' . base64_encode( $this->merchant_id . ':' . htmlspecialchars_decode( $this->shared_secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Base64 used to calculate auth headers.
	}

	/**
	 * Maybe filters out product urls before we send them to Klarna based on settings.
	 *
	 * @param string $url The URL to the product or product image.
	 * @return string|null
	 */
	public function maybe_allow_product_urls( $url ) {
		if ( 'yes' === $this->settings['send_product_urls'] ?? false ) {
			$url = null;
		}
		return $url;
	}

	/**
	 * Gets the error message from the Klarna payments response.
	 *
	 * @param array $response
	 * @return WP_Error
	 */
	public function get_error_message( $response ) {
		$error_message = '';
		// Get the error messages.
		if ( null !== json_decode( $response['body'], true ) ) {
			foreach ( json_decode( $response['body'], true )['error_messages'] as $error ) {
				$error_message = "$error_message $error";
			}
		}
		$code          = wp_remote_retrieve_response_code( $response );
		$error_message = empty( $error_message ) ? $response['response']['message'] : $error_message;
		return new WP_Error( $code, $error_message );
	}
}
