<?php
require_once( WPSC_TE_V2_CLASSES_PATH . '/query-controller.php' );

class WPSC_Controller_Single extends WPSC_Query_Controller {
	public function __construct() {
		parent::__construct();
	}

	public function index() {
		$this->title = get_queried_object()->post_title;
		$this->view = 'single';
		$this->load_lightbox();
	}

	public function load_lightbox() {
		if ( apply_filters( 'wpsc_use_fluidbox', true ) ) {
			wp_enqueue_style( 'wpsc-fluidbox' );
			add_action( 'wp_enqueue_scripts', array( $this, '_fluidbox_scripts' ) );
		}
	}

	public function _fluidbox_scripts() {
		wp_enqueue_script( 'wpsc-fluidbox' );
	}

	public function get_native_template() {
		return locate_template( 'single-wpsc-product.php' );
	}
}
