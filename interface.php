<?php

	function semantria_admin_init() {
		register_setting( 'semantria_settings', 'semantria_consumer_key' );
		register_setting( 'semantria_settings', 'semantria_consumer_secret' );
		
		add_settings_section( 'semantria_settings', 'Semantria Settings', 'semantria_settings_callback', 'semantria-settings' );
		
		add_settings_field( 'semantria_consumer_key', 'Consumer Key', 'semantria_consumer_key_callback', 'semantria-settings', 'semantria_settings' );
		add_settings_field( 'semantria_consumer_secret', 'Consumer Secret', 'semantria_consumer_secret_callback', 'semantria-settings', 'semantria_settings' );
	}
	
	function semantria_admin_menu() {
        add_menu_page( 'WP Semantria', 'WP Semantria', 'manage_options', 'semantria-queue', 'semantria_queue_page', plugins_url( 'wp-semantria/assets/img/icon-16x16.png' ) );
        add_submenu_page( 'semantria-queue', 'Settings', 'Settings', 'manage_options', 'semantria-settings', 'semantria_settings_page' );
		//add_options_page( 'Semantria Settings', 'Semantria Settings', 'manage_options', 'semantria-settings', 'semantria_settings_page' );
	}
	
	function semantria_consumer_key_callback() {
		echo( '<input name="semantria_consumer_key" style="width:250px;" type="text" value="' . get_option( 'semantria_consumer_key' ) . '" />' );
	}
	
	function semantria_consumer_secret_callback() {
		echo( '<input name="semantria_consumer_secret" style="width:250px;" type="text" value="' . get_option( 'semantria_consumer_secret' ) . '" />' );
	}
    
    function semantria_queue_page() {
        global $wpdb;
        
        $semantria_table = $wpdb->prefix . 'semantria_queue';
        $results_total = $wpdb->get_var( "SELECT COUNT(semantria_id) FROM $semantria_table qt INNER JOIN $wpdb->postmeta pm ON qt.semantria_id = pm.meta_value" );
        
        $per_page = 40;
        $num_pages = ceil( $results_total / $per_page );
        $current_page = ( $_GET['paged'] > 0 ? intval( $_GET['paged'] ) : 1 );
        $pagination_url = 'admin.php?page=semantria-queue';
        
        $pagination = paginate_links( array(
            'base' => $pagination_url . '%_%',
            'format' => '&paged=%#%',
            'total' => $num_pages,
            'current' => $current_page
        ) );
        
        $results = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_title, p.post_date, p.post_type, pm.post_id, qt.status, qt.semantria_id, qt.added, qt.closed
            FROM $semantria_table qt INNER JOIN $wpdb->postmeta pm ON qt.semantria_id = pm.meta_value INNER JOIN $wpdb->posts p ON pm.post_id = p.ID
            ORDER BY p.post_date DESC LIMIT %d, %d",
            ( $current_page - 1 ) * $per_page, $per_page
        ) );
        
        echo( '
            <div class="wrap">
                <div class="icon32">
                    <img alt="" src="' . plugins_url( 'wp-semantria/assets/img/icon-32x32.png' ) . '" />
                    <br />
                </div>
				<h2>WP Semantria Queue Log</h2>
                <ul class="subsubsub">
                    <li>
                        Queue
                        |
                    </li>
                    <li>
                        Processing
                        |
                    </li>
                    <li>
                        Expired
                        |
                    </li>
                    <li>
                        Completed
                    </li>
                </ul>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num">Displaying ' . number_format( ( $current_page - 1 ) * $per_page + 1 ) . ' &ndash; ' . number_format( ( $current_page - 1 ) * $per_page + $wpdb->num_rows ) . ' of ' . number_format( $results_total ) . '</span>
                        ' . $pagination . '
                    </div>
                </div>
				<table cellspacing="0" class="wp-list-table widefat fixed posts">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <label class="screen-reader-text" for="cb-select-all-1">Select All</label>
                                <input id="cb-select-all-1" type="checkbox" />
                            </th>
                            <th>Title</th>
                            <th class="column-date">Post Type</th>
                            <th class="column-date">Date Published</th>
                            <th class="column-date">Semantria Queue ID</th>
                            <th class="column-date">Status</th>
                            <th class="column-date">Added to Queue</th>
                            <th class="column-date">Queue Completion</th>
                        </tr>
                    </thead>
                    <tbody>
        ' );
        
        foreach ( $results as $row ) {
            $post_type_obj = get_post_type_object( $row->post_type );
            
            echo( '
                <tr valign="top">
                    <th scope="row" class="check-column">
                        <label class="screen-reader-text" for="cb-select-all-' . $row->post_id . '">' . $row->post_title . '</label>
                        <input id="cb-select-' . $row->post_id . '" name="queue[]" type="checkbox" value="' . $row->post_id . '" />
                    </th>
            ' );
            
            echo( '
                <td>
                    <strong>' . $row->post_title . '</strong>
                    <div class="row-actions">
                        <a href="#">Evaluate</a>
                        |
                        <span class="trash"><a href="#">Hold</a></span>
                    </div>
                </td>
            ' );
            
            echo( '
                    <td>' . $post_type_obj->labels->singular_name . '</td>
                    <td>' . $row->post_date . '</td>
                    <td>' . $row->semantria_id . '</td>
                    <td>' . $row->status . '</td>
                    <td>' . $row->added . '</td>
                    <td>' . $row->closed . '</td>
                </tr>
            ' );
        }
        
        echo( '
                    </tbody>
                </table>
                <div></div>
            </div>
        ' );
    }
	
	function semantria_settings_callback() {
		echo( '<p>Please provide your Consumer Key and Secret which is available at <a href="https://semantria.com/user">https://semantria.com/user</a> (login required).</p>' );
	}
	
	function semantria_settings_page() {
		global $wpdb;
		
		echo( '
			<div class="wrap">
				<div class="icon32">
                    <img src="' . plugins_url( 'wp-semantria/assets/img/icon-32x32.png' ) . '" />
                    <br />
                </div>
				<h2>WP Semantria Settings</h2>
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