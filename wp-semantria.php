<?php
/**
Plugin Name: WP Semantria
Plugin URI: https://github.com/cameronterry/wp-semantria
Description: This plugin connects with your Semantria API account to create new taxonomies from unstructure Post and Page content.  In addition, each post / taxonomy link contains a sentiment score so you can pick additional stories that are positive or negative.
Version: 0.1.1
Author: Cameron Terry
Author URI: https://github.com/cameronterry/
 */

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	require_once( dirname(__FILE__) . '/semantria/session.php' );
	require_once( dirname(__FILE__) . '/semantria/jsonserializer.php' );
	
	require_once( dirname(__FILE__) . '/api.php' );
	require_once( dirname(__FILE__) . '/api-ajax.php' );
	require_once( dirname(__FILE__) . '/inflector.php' );
	require_once( dirname(__FILE__) . '/interface.php' );
	
	/**
	 * Setup the Global variable for the Semantria Session object.
	 */
	if ( get_option( 'semantria_consumer_key', null ) !== null && get_option( 'semantria_consumer_secret', null ) !== null ) {
		$GLOBALS['semantria_session'] = new \Semantria\Session( get_option( 'semantria_consumer_key' ), get_option( 'semantria_consumer_secret' ) , null, 'WordPress' );
	}
	
	function semantria_admin_enqueue( $hook_suffix ) {
        if ( $hook_suffix == 'wp-semantria_page_semantria-settings' || $hook_suffix == 'toplevel_page_semantria-queue' ) {
			wp_register_script( 'handlebars-js', '//cdnjs.cloudflare.com/ajax/libs/handlebars.js/1.0.0/handlebars.min.js' );
            wp_register_script( 'wp-semantria', plugins_url( 'wp-semantria.js', __FILE__ ), array( 'jquery', 'handlebars-js' ) );
            wp_register_style( 'wp-semantria-css', plugins_url( 'assets/css/wp-semantria.css', __FILE__ ) );
            
            wp_enqueue_script( 'wp-semantria' );
            wp_enqueue_style( 'wp-semantria-css' );
		}
	}
	
	function semantria_activation_hook() {
		global $wpdb;
		
		/**
		 * Create a matching Term Relationship table that will allow us
		 * to store the sentiment of each Content / Taxonomy link.
		 */
		$table_name = $wpdb->prefix . 'term_relationships_semantria';
		$sql = "
			CREATE TABLE $table_name (
				semantria_relationship_id BIGINT NOT NULL AUTO_INCREMENT,
				object_id BIGINT NOT NULL,
				term_taxonomy_id BIGINT NOT NULL,
				sentiment FLOAT NOT NULL,
				PRIMARY KEY (semantria_relationship_id)
			);
		";
		
		dbDelta( $sql );
		
		/**
		 * This table is used to keep track of collections or documents
		 * which are queued with Semantria and are to be retrieved.
		 */
		$table_name = $wpdb->prefix . 'semantria_queue';
		$sql = "
			CREATE TABLE $table_name (
				semantria_id VARCHAR(100) NOT NULL,
				added DATETIME NOT NULL,
				closed DATETIME NULL,
				type VARCHAR(16) NOT NULL,
				status VARCHAR(16) NOT NULL,
				semantria_data LONGTEXT NULL,
				PRIMARY KEY (semantria_id)
			)
		";
		
		dbDelta( $sql );
		
		/**
		 * Used to store Semantria generated taxonomies.
		 */
		$table_name = $wpdb->prefix . 'semantria_taxonomy';
		$sql = "
			CREATE TABLE $table_name (
				semantria_taxonomy_id BIGINT NOT NULL AUTO_INCREMENT,
				name VARCHAR(128) NOT NULL,
				name_plural VARCHAR(128) NOT NULL,
				PRIMARY KEY(semantria_taxonomy_id)
			)
		";
		
		dbDelta( $sql );
	}
	
	function semantria_deactivation_hook() {
		$time = wp_next_scheduled( 'semantria_cron_job' );
		wp_clear_scheduled_hook( $time, 'semantria_five_mins', 'semantria_cron_job' );
	}
	
	function semantria_init() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'semantria_taxonomy';
		$taxonomies = $wpdb->get_results( "SELECT * FROM $table_name" );
		
		foreach ( $taxonomies as $taxonomy ) {
			$plural_lowercase = strtolower( $taxonomy->name_plural );
			
			$labels = array(
				'name'                       => $taxonomy->name_plural,
				'singular_name'              => $taxonomy->name,
				'menu_name'                  => $taxonomy->name_plural,
				'all_items'                  => "All $taxonomy->name_plural",
				'parent_item'                => "Parent $taxonomy->name",
				'parent_item_colon'          => "Parent $taxonomy->name:",
				'new_item_name'              => "New $taxonomy->name Name",
				'add_new_item'               => "Add New $taxonomy->name",
				'edit_item'                  => "Edit $taxonomy->name",
				'update_item'                => "Update $taxonomy->name",
				'separate_items_with_commas' => "Separate $plural_lowercase with commas",
				'search_items'               => "Search $plural_lowercase",
				'add_or_remove_items'        => "Add or remove $plural_lowercase",
				'choose_from_most_used'      => "Choose from the most used $plural_lowercase",
				'popular_items'				 => "Popular $plural_lowercase"
			);
			
			$capabilities = array(
				'manage_terms' => 'manage_categories',
				'edit_terms' => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_posts'
			);
			
			$args = array(
				'labels'                     => $labels,
				'hierarchical'               => false,
				'public'                     => true,
				'show_ui'                    => true,
				'show_tagcloud'              => true
			);
			
			register_taxonomy( semantria_taxonomy_name( $taxonomy->name_plural ), array( 'page', 'post' ), $args );
		}
	}
	
	function semantria_post_handler( $post_id ) {
		if ( false === wp_is_post_revision( $post_id ) && get_post_meta( $post_id, 'semantria_queue_id', true ) === '' ) {
			semantria_commit_document( $post_id );
		}
	}
	
	function semantria_post_deleted_handler( $post_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'term_relationships_semantria';
		$wpdb-query( $wpdb->prepare( "DELETE FROM $table_name WHERE object_id = %d", $post_id ) );
	}
	
	function semantria_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		global $wpdb;
		
		$terms_remove = array( $tt_ids, $old_tt_ids );
		$terms_remove_ids = array();
		
		foreach ( $terms_remove as $term ) {
			$terms_remove_ids[] = $term['term_id'];
		}
		
		$table_name = $wpdb->prefix . 'term_relationships_semantria';
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE object_id IN(%s)", implode( $terms_remove_ids ) ) );
	}
	
	function semantria_ingestion_complete() {
		echo( 'finished' );
		update_option( 'semantria_ingestion_complete', 'yes' );
		
		if ( wp_next_scheduled( 'semantria_cron_job' ) === false ) {
			//wp_schedule_event( time(), 'semantria_five_mins', 'semantria_cron_job' );
		}
	}
	
	function semantria_cron_job() {
		global $wpdb;
		
		/**
		 * Handles items in the queue which are of status "processing", which is that the
		 * item has been sent to Semantria and a response is received.
		 */
		semantria_process_queue();
		
		$queue_table = $wpdb->prefix . 'semantria_queue';
		$now = new DateTime();
		$item_date = new DateTime();

		/**
		 * Handles items in the queue which are of status "queued", which means that the
		 * data has been sent to Semantria but a response with the analysis needs to be
		 * acquired before moving to Processing.
		 * 
		 * However, if the "queued" item is older than 24 hours, then the item is set to
		 * "expired" as Semantria will not retain the information.
		 */
		$queue = $wpdb->get_results( "SELECT pm.post_id, qt.semantria_id, qt.added, qt.type FROM $queue_table qt INNER JOIN $wpdb->postmeta pm ON qt.semantria_id = pm.meta_value WHERE qt.status = 'queued' ORDER BY added LIMIT 0, 50" );

		if ( false === empty( $queue ) ) {
			foreach( $queue as $item ) {
				$item_date = new DateTime( $item->added );

				if ( 1 >= $item_date->diff( $now )->d ) {
					semantria_get_document( $item->post_id, $item->semantria_id, $item->type );
				}
				else {
					semantria_queue_expire( $item->semantria_id );
				}
			}
		}
	}
	
	function semantria_cron_schedule( $schedules ) {
		$schedules['semantria_five_mins'] = array(
			'interval' => 300,
			'display' => __( 'Five Minutes' )
		);
		
		return $schedules;
	}
	
	if ( is_admin() ) {
		/* Activation Hooks */
		register_activation_hook( __FILE__, 'semantria_activation_hook' );
		register_deactivation_hook( __FILE__, 'semantria_deactivation_hook' );
		
		add_action( 'admin_init', 'semantria_admin_init' );
		add_action( 'admin_menu', 'semantria_admin_menu' );
		
		add_action( 'admin_enqueue_scripts', 'semantria_admin_enqueue' );
	}
	
	add_filter( 'cron_schedules', 'semantria_cron_schedule' );
	
	add_action( 'publish_post', 'semantria_post_handler' );
	add_action( 'publish_page', 'semantria_post_handler' );
	add_action( 'trashed_post', 'semantria_post_delete_handler' );
	add_action( 'set_object_terms', 'semantria_set_object_terms', 10, 6 );
	add_action( 'semantria_cron_job', 'semantria_cron_job' );
	add_action( 'init', 'semantria_init' );
	
?>