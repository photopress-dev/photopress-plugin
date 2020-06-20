<?php

class pp_taxonomy_meta extends pp_meta {
	
	public function load() {
		
		if ( ! empty( $this->object_id ) && ! empty( $this->key_group ) ) {
			
			$this->properties = get_option( $this->makeObjectGuid( $this->object_id, $this->key_group ) );
		}
	}
	
	public function save() {
		
		if ( $this->is_dirty && ! empty( $this->object_id ) && ! empty( $this->key_group ) ) {
						
			update_option( $this->makeObjectGuid( $this->object_id, $this->key_group ), $this->properties );
			
			$this->is_dirty = false;
		}
	}
	
	public function delete() {
		
		if ( ! empty( $this->object_id ) && ! empty( $this->key_group ) ) {
		
			delete_option( $this->makeObjectGuid( $this->object_id, $this->key_group ) );
		}
	}

	/**
	 * Makes unique object key from the taxonomy term's slug
	 * this is needed to store the unique object in WordPress's OPTIONS table
	 */
	public function makeObjectGuid( $object_id, $key_group ) {
		
		return sprintf('pp_taxonomy_meta_%s_%s', $object_id, $key_group ); 
	}
}

class pp_post_meta extends pp_meta {
	
	public function load() {
		
		$properties = array();
		
		if ( ! empty( $this->key_group ) && ! empty( $this->object_id ) ) {
			
			$properties = get_post_meta( $this->object_id, $this->key_group );			
		}

		if ( is_array( $properties) ) {
			
			$this->properties = $properties;
		
		} else {
			
			$this->properties[ ] = $properties;	
		}
	}
	
	public function save() {
		
		if ( $this->init 
			 && $this->is_dirty 
			 && ! empty( $this->key_group ) 
			 && ! empty( $this->object_id ) 
		) {
			
			update_post_meta( $this->object_id, $this->key_group, $this->properties );
			$this->is_dirty = false;
		}
	}
	
	public function delete() {
		
		if ( ! empty( $this->key_group ) 
			 && ! empty( $this->object_id ) ) {
			
			delete_post_meta( $this->object_id, $this->key_group, $this->properties );
		}
	}
}

class pp_meta {
	
	public $init;
	public $properties = array();
	public $is_dirty = false;
	public $object_type;
	public $object_id;
	public $key_group;
	
	public function __construct( $params = array()  ) {
		
		if ( array_key_exists('object_id', $params ) ) {
		
			$this->object_id = $object_id;
		}
		
		if ( array_key_exists('key_group', $params ) ) {
		
			$this->key_group = $key_group;
		}
		
		$this->load();
		
		$this->init = true;
	}
	
	public function get( $key ) {
		
		if ( is_array( $this->properties ) && array_key_exists( $key, $this->properties ) ) {
			
			return $this->properties[ $key ];	
		}
	}
	
	public function set( $key, $value ) {
		
		$this->properties[ $key ] = $value;
		$this->is_dirty = true;
	}
	
	public function un_set( $key ) {
		
		if ( is_array( $this->properties ) && array_key_exists( $key, $this->properties ) ) {
			unset( $this->properties[ $key ] );
			$this->is_dirty = true;
		}
	}
	
	public function delete() {
		
		return false;
	}
	
	public function load() {
		
		return false;
	}
	
	public function save() {
		
		return false;
	}
	
	public function __destruct() {
	
		if ( $this->is_dirty ) {
			
			$this->save();
		}
	}
}

?>