<?php

	function semantria_admin_init() {
		register_setting( 'semantria_settings', 'semantria_consumer_key' );
        register_setting( 'semantria_settings', 'semantria_consumer_secret' );
		register_setting( 'semantria_settings', 'semantria_mode_selection', 'semantria_mode_selection_validation_logic' );
		
		add_settings_section( 'semantria_settings', 'Semantria Settings', 'semantria_settings_callback', 'semantria-settings' );
		
		add_settings_field( 'semantria_consumer_key', 'Consumer Key', 'semantria_consumer_key_callback', 'semantria-settings', 'semantria_settings' );
        add_settings_field( 'semantria_consumer_secret', 'Consumer Secret', 'semantria_consumer_secret_callback', 'semantria-settings', 'semantria_settings' );
		add_settings_field( 'semantria_mode_selection', 'Mode', 'semantria_mode_callback', 'semantria-settings', 'semantria_settings' );
	}
	
	function semantria_admin_menu() {
        add_menu_page( 'WP Semantria', 'WP Semantria', 'manage_options', 'semantria-queue', 'semantria_queue_page', plugins_url( '/assets/img/icon-16x16.png', __FILE__ ) );
        add_submenu_page( 'semantria-queue', 'Settings', 'Settings', 'manage_options', 'semantria-settings', 'semantria_settings_page' );
		//add_options_page( 'Semantria Settings', 'Semantria Settings', 'manage_options', 'semantria-settings', 'semantria_settings_page' );
	}
	
	function semantria_consumer_key_callback() {
		echo( '<input name="semantria_consumer_key" style="width:250px;" type="text" value="' . get_option( 'semantria_consumer_key' ) . '" />' );
	}
	
	function semantria_consumer_secret_callback() {
		echo( '<input name="semantria_consumer_secret" style="width:250px;" type="text" value="' . get_option( 'semantria_consumer_secret' ) . '" />' );
	}

    function semantria_mode_callback() {
        $selected_value = get_option( 'semantria_mode_selection', 'automatic' );

        $values = array( 'automatic', 'manual' );

        echo( '<select name="semantria_mode_selection">' );

        foreach ( $values as $value ) {
            printf( '<option %2$s value="%1$s">%1$s</option>', $value, ( $value === $selected_value ? 'selected="selected"' : '' ) );
        }

        echo( '</select>' );
    }

    function semantria_mode_selection_validation_logic( $input ) {
        if ( 'automatic' === $input ) {
            semantria_cron_create();
        }
        else if ( 'manual' === $input ) {
            semantria_cron_clear();
        }

        return $input;
    }
    
    function semantria_queue_page() {
        global $wpdb;

        $page_url = admin_url( 'admin.php?page=semantria-queue' );
        $semantria_status = '';
        
        if ( isset( $_GET['status'] ) ) {
            $semantria_status = $_GET['status'];
            
            if ( semantria_status_is_valid( $semantria_status ) === false ) {
                wp_die( 'Invalid Semantria Queue Status.' );
            }
        }
        else {
            $semantria_status = 'queued';
        }
        
        $semantria_table = $wpdb->prefix . 'semantria_queue';
        $results_total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(semantria_id) FROM $semantria_table qt WHERE qt.status = %s", $semantria_status ) );
        
        $per_page = 40;
        $num_pages = ceil( $results_total / $per_page );
        $current_page = ( $_GET['paged'] > 0 ? intval( $_GET['paged'] ) : 1 );
        
        $pagination = paginate_links( array(
            'base' => $page_url . '%_%',
            'format' => '&paged=%#%',
            'total' => $num_pages,
            'current' => $current_page
        ) );
        
        $results = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_title, p.post_date, p.post_type, qt.post_id, qt.status, qt.semantria_id, qt.added, qt.closed
            FROM $semantria_table qt INNER JOIN $wpdb->posts p ON qt.post_id = p.ID
            WHERE qt.status = %s
            ORDER BY p.post_date DESC LIMIT %d, %d",
            $semantria_status,
            ( $current_page - 1 ) * $per_page, $per_page
        ) );
        
        echo( '
            <div class="wrap wp-semantria-content">
                <div class="icon32">
                    <img alt="" src="' . plugins_url( 'wp-semantria/assets/img/icon-32x32.png' ) . '" />
                    <br />
                </div>
				<h2>WP Semantria Queue</h2>
                <ul class="subsubsub">
                    <li>
                        <a ' . ( $semantria_status == 'queued' ? 'class="current"' : '' ) . ' href="' . add_query_arg( 'status', 'queued', $page_url ) . '">Queued</a>
                        |
                    </li>
                    <li>
                        <a ' . ( $semantria_status == 'processing' ? 'class="current"' : '' ) . ' href="' . add_query_arg( 'status', 'processing', $page_url ) . '">Processing</a>
                        |
                    </li>
                    <li>
                        <a ' . ( $semantria_status == 'expired' ? 'class="current"' : '' ) . ' href="' . add_query_arg( 'status', 'expired', $page_url ) . '">Expired</a>
                        |
                    </li>
                    <li>
                        <a ' . ( $semantria_status == 'complete' ? 'class="current"' : '' ) . ' href="' . add_query_arg( 'status', 'complete', $page_url ) . '">Completed</a>
                        |
                    </li>
                    <li>
                        <a ' . ( $semantria_status == 'stopped' ? 'class="current"' : '' ) . ' href="' . add_query_arg( 'status', 'stopped', $page_url ) . '">Stopped</a>
                    </li>
                </ul>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num">Displaying ' . number_format( ( $current_page - 1 ) * $per_page + 1 ) . ' &ndash; ' . number_format( ( $current_page - 1 ) * $per_page + $wpdb->num_rows ) . ' of ' . number_format( $results_total ) . '</span>
                        ' . $pagination . '
                    </div>
                </div>
				<table cellspacing="0" class="wp-list-table widefat fixed wp-semantria-table">
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
        
        if ( empty( $results ) ) {
            echo( '
                <tr>
                    <td colspan="8" style="text-align:center;">
                        <strong>No queue records found.</strong>
                    </td>
                </tr>
            ' );
        }
        
        foreach ( $results as $row ) {
            $post_type_obj = get_post_type_object( $row->post_type );
            
            echo( '
                <tr id="row-' . $row->post_id . '" valign="top">
                    <th scope="row" class="check-column">
                        <label class="screen-reader-text" for="cb-select-all-' . $row->post_id . '">' . $row->post_title . '</label>
                        <input id="cb-select-' . $row->post_id . '" name="queue[]" type="checkbox" value="' . $row->post_id . '" />
                    </th>
                    <td>
                        <strong>' . $row->post_title . '</strong>
                        <div class="row-actions">
            ' );
            
            if ( $semantria_status == 'queued' ) {
                printf( '
                    <a class="update-status" data-semantria-id="%1$s" data-next-status="processing" data-post-id="%2$s" href="#">Process</a>
                    | <span class="trash"><a class="update-status" data-semantria-id="%1$s" data-next-status="stopped" data-post-id="%2$s" href="#">Stop</a></span>',
                    $row->semantria_id,
                    $row->post_id
                );
            }
            else if ( $semantria_status == 'processing' ) {
                printf( '<a class="evaluate" data-semantria-id="%s" data-post-id="%s" href="#">Evalulate</a>', $row->semantria_id, $row->post_id );
            }
            else if ( $semantria_status == 'complete' ) {
                printf( '<a class="evaluate" data-semantria-id="%s" data-post-id="%s" href="#">Review</a>', $row->semantria_id, $row->post_id );
            }
            else if ( 'expired' === $semantria_status ) {
                printf( '<a class="update-status" data-semantria-id="%1$s" data-next-status="requeue" data-post-id="%2$s" href="#">Requeue</a>', $row->semantria_id, $row->post_id );
            }
            else if ( $semantria_status == '' ) {}
            
            echo( '
                        </div>
                    </td>
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
                <div id="pnlSemantriaModal" class="semantria-modal">
                    <div class="semantria-modal-content">
                        <div class="post">
                            <div class="inner" rel="post"></div>
                        </div>
                        <div class="options">
                            <div class="inner">
                                <div rel="options"></div>
                                <p class="buttons">
                                    <button class="button close-modal">Close</button>
                                    <button class="button button-primary save-modal">Apply Terms</button>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div id="pnlLoading" class="loader">
                        <img alt="Loading" src="' . plugins_url( 'wp-semantria/assets/img/loader-48x48.gif' ) . '" />
                    </div>
                </div>
                <input id="hdnGetNonce" type="hidden" value="' . wp_create_nonce( 'wp_semantria_get_security' ) . '" />
                <input id="hdnSaveNonce" type="hidden" value="' . wp_create_nonce( 'wp_semantria_save_security' ) . '" />
                <input id="hdnUpdateNonce" type="hidden" value="' . wp_create_nonce( 'wp_semantria_update_security' ) . '" />
                <div id="pnlSemantriaBackdrop" class="semantria-modal-backdrop"></div>
            </div>
        ' );
    }
	
	function semantria_settings_callback() {
		echo( '<p>Please provide your Consumer Key and Secret which is available at <a href="https://semantria.com/user">https://semantria.com/user</a> (login required).</p>' );
	}
	
	function semantria_settings_page() {
		global $wpdb;

		echo( '
			<div class="wrap wp-semantria-content">
				<div class="icon32">
                    <img src="' . plugins_url( 'wp-semantria/assets/img/icon-32x32.png' ) . '" />
                    <br />
                </div>
				<h2>WP Semantria Settings</h2>
        ' );

        if ( isset( $_REQUEST['settings-updated'] ) ) {
            echo( '
                <div class="updated settings-error">
                    <p><strong>Settings saved.</strong></p>
                </div>
            ' );
        }

        echo( '
				<form method="post" action="options.php">
		' );
		
		settings_fields( 'semantria_settings' );
		do_settings_sections( 'semantria-settings' );
		submit_button();
		
		echo( '
			<h3>Sementria Ingestion</h3>
		' );
		
        $total = semantria_get_unprocessed_post_count();

		if ( get_option( 'semantria_consumer_key', null ) !== null && $total > 0 ) {
			echo( '
        				<p>There are currently Posts and / or Pages which have not been processed by Semantria.  This process will send all current Posts to Semantria for processing.</p>
        				<div class="button-container">
                            <input id="cmdPerformDataIngestion" name="performDataIngestion" class="button-primary" type="button" value="Perform Data Ingestion" />
                        </div>
                        <div class="loader-container">
                            <div class="counter">
                				<span id="ltlCurrentPosition" style="font-weight:bold;padding-left:7px;">0</span>
                				of
                				<span id="ltlTotalRecords" style="font-weight:bold;">' . $total . '</span>
                            </div>
                            <div id="ltlLoading" class="loader">
                                <img alt="Loading" src="' . site_url( '/wp-includes/images/wpspin.gif' ) . '" />
                            </div>
                        </div>
                        <input id="hdnIngestionNonce" type="hidden" value="' . wp_create_nonce( 'wp_semantria_ingestion_security' ) . '" />
    				</form>
    			</div>
    		' );
        }
        else {
            printf( '<p>All posts are accounted for and are in the Semantria Queue.  Go to <a href="%s">WP Semantria page</a> to view the progress of each document.</p>', admin_url( 'admin.php?page=semantria-queue' ) );
        }
	}

?>