<?php

class pp_view {
	
	public $data = array();
	
	public function __construct( $data = '' ) {
		
		if ($data) {
			$this->data = $data;
		}
	}
	
	public function output() {
		
		$this->pre();
		$o = $this->render();
		return $this->post( $o );
	}
	
	public function pre() {
	
		return false;
	}
	
	public function post( $o ) {
		
		return $o;
	}
	
	// getter
	public function get( $name ) {
		
		if (array_key_exists( $name, $this->data ) ) {
			
			return $this->data[ $name ];	
		}
	}
}

?>