<?php
    
    /**
     * Returns a JSON object containing the processed terminology from Semantria
     * as well as basic post information such as Title and Content.  This is used
     * on the "Evaluate" screen available via the Semantria Queue interface.
     */
    function semantria_ajax_get() {
        $semantria_queue_id = $_POST['semantria_id'];
        $post_id = intval( $_POST['post_id'] );
        
        $data = semantria_get_data( $semantria_queue_id );
        
        if ( empty( $data ) ) {
            $data = array();
        }
        
        $post_data = get_post( $post_id );
        
        $data['article'] = array();
        
        if ( empty( $post_data ) === false ) {
            $data['article']['post_id'] = $post_data->ID;
            $data['article']['title'] = $post_data->post_title;
            $data['article']['body'] = apply_filters( 'the_content', $post_data->post_content );
            $data['article']['terms'] = array();
            $data['article']['terms']['semantria'] = array();
            
            /**
             * It would seem the only way to do this is to grab every available taxonomy and then
             * look to see if the post has any.  Yes!  Very inefficient but the Codex don't lie!!
             * 
             * http://codex.wordpress.org/Function_Reference/get_the_terms
             */
            $available_taxonomies = get_object_taxonomies( $post_data->post_type );
            
            if ( empty( $available_taxonomies ) === false ) {
                foreach ( $available_taxonomies as $taxonomy ) {
                    if ( strrpos( $taxonomy, 'semantria-' ) !== false ) {
                        $terms_data = get_the_terms( $post_data->ID, $taxonomy );
                        
                        if ( $terms_data !== false ) {
                            $data['article']['terms']['semantria'] = array_merge( $data['article']['terms']['semantria'], $terms_data );
                        }
                    }
                    else {
                        $terms_data = get_the_terms( $post_data->ID, $taxonomy );
                        $data['article']['terms'][$taxonomy] = ( $terms_data === false ? array() : $terms_data );
                    }
                }
            }
        }
        
        echo( json_encode( $data ) );
        die();
    }
    
    /**
     * 
     */
    function semantria_ajax_save() {
        check_ajax_referer( 'wp_semantria_save_security', 'security' );

        $data = $_POST['data'];
        $post_data = get_post( $data['article']['post_id'] );
        
        if ( empty( $data ) === false ) {
            semantria_clear_all_terms( $post_data->ID, $data['id'] );
            semantria_process_document_data( $post_data->ID, $data );
            semantria_queue_complete( $data['id'] );
            
            echo( 'done' );
        }
        else {
            echo( 'error' );
        }
        
        die();
    }
    
    /**
	 * This is the AJAX call used to perform the initial ingestion once the plugin
     * has a consumer key and consumer secret for Semantria provided.  Currently,
     * this plugin will take all standard Page and Post type records and submit
     * them to Semantria.
     * 
     * Support for custom post types is currently not available.
	 * 
     * @uses semantria_commit_document() Semantria method for sending a document to Semantria for processing.
     * @uses semantria_ingestion_complete() Semantria method for completing the ingestion process.
	 * @uses get_post_meta() WordPress API for retrieving metadata for a specific post.
	 * @uses wp_cache_flush() WordPress API for flushing WordPress' inbuilt cache.
	 */
    function semantria_ajax_ingest_all() {
		global $wpdb;
		
		set_time_limit( 600 );
		
		$offset = intval( $_POST['offset'] );
		$count = 100;
		
        /**
         * Todo: Add support for custom Post Types.  This will require a front-end component too.
         */
		$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status LIKE 'publish'AND post_type IN('post', 'page') ORDER BY ID LIMIT $offset, $count" );
		
		if ( count( $post_ids ) == 0 ) {
			semantria_ingestion_complete();
		}
		else if ( count( $post_ids ) < $count ) {
			foreach( $post_ids as $post_id ) {
				if ( get_post_meta( $post_id, 'semantria_queue_id', true ) === '' ) {
					semantria_commit_document( $post_id );
				}
			}
			
			semantria_ingestion_complete();
		}
		else {
			foreach( $post_ids as $post_id ) {
				if ( get_post_meta( $post_id, 'semantria_queue_id', true ) === '' ) {
					semantria_commit_document( $post_id );
				}
			}
			
			echo( $offset + $count );
		}
		
		wp_cache_flush();
		die();
	}
    
    /**
     * Progresses a Queue record from one part of the Semantria process to the
     * next part - so for instance a Queued document will need to make another
     * call to Semantria in order to retrieve the Entities and Topics.
     *
     * The name is a little misleading as it implies it will update the status
     * field on the table - but in fact it will execute the processes around
     * a record moving from one step to the next.
     */
    function semantria_ajax_update_status() {
        $semantria_queue_id = $_POST['semantria_id'];
        $post_id = $_POST['post_id'];
        $new_status = $_POST['new_status'];
        $echo_value = 'unable';
        
        if ( semantria_status_is_valid( $new_status ) === false ) {
            echo( 'error' );
            die();
        }
        
        /**
         * Handle Processing for the document - this is for Documents in the queue which
         * are sent to Semantria but haven't called the API service to see if the Document
         * has been processed with entities.
         */
        if ( $new_status == 'processing' ) {
            semantria_get_document( $post_id, $semantria_queue_id );
            $echo_value = 'done';
        }

        if ( $new_status == 'complete' ) {
            semantria_queue_complete( $semantria_queue_id );
            $echo_value = 'done';
        }
        
        echo( $echo_value );
        die();
    }
    
    if ( is_admin() ) {
        add_action( 'wp_ajax_wp_semantria_ingest_all', 'semantria_ajax_ingest_all' );
        add_action( 'wp_ajax_wp_semantria_get', 'semantria_ajax_get' );
        add_action( 'wp_ajax_wp_semantria_save', 'semantria_ajax_save' );
        add_action( 'wp_ajax_wp_semantria_update_status', 'semantria_ajax_update_status' );
    }
    
?>