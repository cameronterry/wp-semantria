<?php

	function semantria_admin_init() {
		register_setting( 'semantria_settings', 'semantria_consumer_key' );
		register_setting( 'semantria_settings', 'semantria_consumer_secret' );
		
		add_settings_section( 'semantria_settings', 'Semantria Settings', 'semantria_settings_callback', 'semantria-settings' );
		
		add_settings_field( 'semantria_consumer_key', 'Consumer Key', 'semantria_consumer_key_callback', 'semantria-settings', 'semantria_settings' );
		add_settings_field( 'semantria_consumer_secret', 'Consumer Secret', 'semantria_consumer_secret_callback', 'semantria-settings', 'semantria_settings' );
	}
	
	function semantria_admin_menu() {
		add_options_page( 'Semantria Settings', 'Semantria Settings', 'manage_options', 'semantria-settings', 'semantria_settings_page' );
	}
	
	function semantria_consumer_key_callback() {
		echo( '<input name="semantria_consumer_key" style="width:250px;" type="text" value="' . get_option( 'semantria_consumer_key' ) . '" />' );
	}
	
	function semantria_consumer_secret_callback() {
		echo( '<input name="semantria_consumer_secret" style="width:250px;" type="text" value="' . get_option( 'semantria_consumer_secret' ) . '" />' );
	}
	
	function semantria_settings_callback() {
		echo( '<p>Please provide your Consumer Key and Secret which is available at <a href="https://semantria.com/user">https://semantria.com/user</a> (login required).</p>' );
	}
	
	function semantria_settings_page() {
		global $wpdb;
		
		echo( '
			<div class="wrap">
				<div id="icon-options-general" class="icon32"><br /></div>
				<h2>WordPress Semantria Settings</h2>
				<form method="post" action="options.php">
		' );
		
		settings_fields( 'semantria_settings' );
		do_settings_sections( 'semantria-settings' );
		submit_button();
		
		echo( '
			<h3>Sementria Ingestion</h3>
		' );
		
		if ( get_option( 'semantria_consumer_key', null ) !== null && get_option( 'semantria_ingestion_complete', null ) === null ) {
			$total = $wpdb->get_var( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_status LIKE 'publish' AND post_type IN('page', 'post')" );
			
			echo( '
				<p>Now that the Semantria Consumer Key and Consumer Secret have been inputted, you are now ready to perform the data injestion.  This process will send all current Posts to Semantria for processing.</p>
				<input id="cmdPerformDataIngestion" name="performDataIngestion" class="button-primary" type="button" value="Perform Data Ingestion" />
				<span id="ltlCurrentPosition" style="font-weight:bold;padding-left:7px;">0</span>
				of
				<span id="ltlTotalRecords" style="font-weight:bold;">' . $total . '</span>
			' );
		}
		
		if ( get_option( 'semantria_ingestion_complete', null ) === 'yes' ) {
			echo( '
				<p>Ingestion is complete.  Please consult your taxonomy admin screens to see what we\'ve gleamed from your Pages and Posts :-)</p>
			' );
		}
		
		echo( '
				</form>
			</div>
		' );
	}

?>