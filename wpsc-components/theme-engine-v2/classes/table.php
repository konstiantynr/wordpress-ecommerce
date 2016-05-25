<?php

class WPSC_Table {
	public $columns = array();
	public $items   = array();

	public function __construct() {
	}

	public function print_column_headers() {
		foreach ( $this->columns as $name => $title ) {
			$class = str_replace( '_', '-', $name );
			echo "<div class='wpsc-cart-cell-header {$class}' scope='col'>" . esc_html( $title ) . "</div>";
		}
	}

	public function display_rows() {

		foreach ( $this->items as $key => $item ) {
			echo '<div class="wpsc-cart-item">';
			foreach( array_keys( $this->columns ) as $column ) {
				$class = str_replace( '_', '-', $column );
				echo '<div class="wpsc-cart-cell ' . $class . '">';
				$callback = "column_{$column}";

				if ( is_callable( array( $this, "column_{$column}") ) ) {
					$this->$callback( $item, $key );
				} else {
					$this->column_default( $item, $key, $column );
				}

				echo '</div>';
			}
			echo '</div>';
		}
	}

	protected function before_table() {
		// subclass should override this
	}

	protected function after_table() {
		// subclass should override this
	}

	protected function column_default( $item, $key, $column ) {
		// subclass should override this
	}

	protected function get_table_classes() {
		return array( 'wpsc-table' );
	}

	public function display() {
		$this->before_table();
		include( WPSC_TE_V2_SNIPPETS_PATH . '/table-display.php' );
		$this->after_table();
	}
}