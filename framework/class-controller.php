<?php

class pp_controller {
	
	public $params;
	
	public $data;
	
	public $view;
	
	public function __construct( $params ) {
		
		$this->params = $params;
	}
	
	public function pre() {
		
		return false;
	}
	
	public function post() {
		
		return false;
	}
	
	public function doAction() {
		
		$this->pre();
		$data = $this->action();
		$this->post();
		
		if ( ! $data ) {
			
			$data = $this->data;	
		}
		
		return $data;
	}
	
	public function setView( $view_name ) {
		
		$this->view = $view_name;
	}
	
	public function getView() {
		
		return $this->view;
	}
	
	public function set( $name, $value ) {
		
		$this->data[ $name ] = $value;
	}
	
	public function get( $key ) {
		
		if ( array_key_exists( $key, $this->data ) ) {
			
			return $this->data[ $key ];
		}
	}
}

?>