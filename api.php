<?php

	/**
	 * Adds a new record on the Semantria Queue table which will be used
	 * by the Cron job to retrieve the analysis later on once Semantria is
	 * finished.
	 * 
	 * @global object $wpdb The WordPress Database object.
	 * @param string $semantria_id The Queue ID provided in the call to Semantria.
	 * @param string $type Determines if the Semantria ID is for a Semantria Document or Collection.
	 */
	function semantria_add_queue( $semantria_id, $post_id, $type ) {
		global $wpdb;
		
		$now = new DateTime();
		
		$wpdb->insert(
			$wpdb->prefix . 'semantria_queue',
			array(
				'semantria_id' => $semantria_id,
				'post_id' => $post_id,
				'added' => $now->format( 'Y-m-d H:i:s' ),
				'status' => 'queued',
				'type' => $type
			)
		);
	}

	/**
	 * Assembles the necessary data from a given Post ID which is to
	 * be sent to Semantria.
	 * 
	 * Also contains a filter which can be used by third party plugins
	 * to ensure additional text to be analysed can be included with
	 * the main body of the text.
	 *
	 * Please note that Post ID is Post Type agnostic and will work
	 * with all in-built types and any custom Post Types.
	 * 
	 * @param int $post_id WordPress Post ID.
	 * @uses apply_filters Calls 'semantria_build_document_content' after retrieving the Post Content.
	 * @return Array A unique ID for Semantria and a concatenation of all major text for the Post.
	 */
	function semantria_build_document( $post_id ) {
		$post = get_post( $post_id );
		$content = array( strip_tags( $post->post_content ) );
		
		/**
		 * This is used to provide other plugins the ability to include additional text to
		 * be analysed within Semantria.
		 */
		$content = apply_filters( 'semantria_build_document_content', $content, $post_id );
		$whole_content = implode( "\n\n", $content );
		
		if ( empty( $whole_content ) ) {
			return null;
		}

		if ( strlen( $whole_content ) > 8192 ) {
			return array(
				'id' => uniqid( '' ),
				'documents' => str_split( $whole_content, 8192 )
			);
		}
		else {
			return array(
				'id' => uniqid( '' ),
				'text' => implode( "\n\n", $content )
			);
		}
	}
    
    /**
     * Retrieves the processed Semantria object and removes all Entities
     * and Themes which may have been linked to the post.
     * 
     * Please note this method will only remove items which were returned
     * by Semantria.  Any user terms added outside of Semantria's analysis
     * WILL remain.
     */
    function semantria_clear_all_terms( $post_id, $semantria_queue_id ) {
        global $wpdb;
        
        $entity_term_ids = array();
        $theme_term_ids = array();
        $data = semantria_get_data( $semantria_queue_id );
        $inflector = new Inflector();
        
        if ( empty( $data ) === false ) {
            if ( empty( $data['entities'] ) === false ) {
                foreach ( $data['entities'] as $entity ) {
                    $plural = strtolower( $inflector->pluralize( $entity['entity_type'] ) );
                    $term = get_term_by( 'name', $entity['title'], "semantria-$plural" );
                    
                    if ( $term !== false ) {
                        $entity_term_ids[] = $term->term_taxonomy_id;
                    }
                }
            }
            
            if ( empty( $data['entities'] ) === false ) {
                foreach ( $data['themes'] as $theme ) {
                    $term = get_term_by( 'name', $theme['title'], 'post_tag' );
                    
                    if ( $term !== false ) {
                        $theme_term_ids[] = $term->term_taxonomy_id;
                    }
                }
            }
            
            $semantria_term_table = $wpdb->prefix . 'term_relationships_semantria';
            
            /**
             * Put the term IDs together in the one string.
             */
            $in_delete_terms = "'" . implode("', '", array_merge( $entity_term_ids, $theme_term_ids ) ) . "'";
            
            /**
             * And then blitz them from the term_relationship tables.
             */
            $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id IN ($in_delete_terms)", $post_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM $semantria_term_table WHERE object_id = %d", $post_id ) );
        }
    }

    /**
	 * Using the Post ID, assembles the information needed for a
	 * given post (Posts, Pages, Custom Post Types) and then sends
	 * the data to Semantria for processing.
	 * 
	 * This function also adds a record to the Semantria Queue
	 * table as well as update the Post Metadata to link back to.
	 * 
	 * @global object $semantria_session Semantria PHP Wrapper Session object.
	 * @param int $post_id WordPress Post ID
	 * @uses semantria_add_queue() Adds the document to the Semantria queue if successfully sent.
	 * @uses update_post_meta() WordPress API call to add the Semantria ID to the Post Metadata.
	 */
	function semantria_commit_document( $post_id ) {
		global $semantria_session;
		
		$document = semantria_build_document( $post_id );
		$queue_type = 'document';
		
		/**
		 * If the document returned is null, then that means the post->content
		 * field was empty and thus no processing can be performed.
		 */
		if ( $document == null ) {
			return null;
		}
		
		if ( array_key_exists( 'documents', $document ) ) {
			$status = $semantria_session->queueCollection( $document );
			$queue_type = 'collection';
		}
		else {
			$status = $semantria_session->queueDocument( $document );
		}
		
		if ( $status == 202 ) {
			semantria_add_queue( $document['id'], $post_id, $queue_type );
			update_post_meta( $post_id, 'semantria_queue_id', $document['id'] );
			
			return $document['id'];
		}
		else {
			return null;
		}
	}

	/**
	 * When a new entity is provided from the Semantria results, this method
	 * is used to create the taxonomy within WordPress for use.
	 * 
	 * @param string $name The name to be given to the new taxonomy.
	 * @uses Inflector To pluralise the taxonomy name.
	 * @uses register_taxonomy() WordPress API for creating new taxonomies.
	 */
	function semantria_create_taxonomy( $name ) {
		global $wpdb;
		
		$inflector = new Inflector();
		$plural = $inflector->pluralize( $name );
		
		/**
		 * Regex taxonomies are currently ignored as it's plucking out email addresses and the such
		 * which great, but could pose a security risk or expose someone's email address to a web
		 * crawler by spammers (as one example).
		 * 
		 * Needs a bit more thought and care before exposing it to the public facing side.
		 */
		if ( semantria_taxonomy_exists( $name ) == false && strtolower( $name ) != 'regex' ) {
			$wpdb->insert(
				$wpdb->prefix . 'semantria_taxonomy',
				array(
					'name' => $name,
					'name_plural' => $plural
				)
			);
		}
	}

	/**
	 * Removes the CRON job from the WordPress schedule so that it does not
	 * execute.
	 *
	 * @uses wp_clear_scheduled_hook() WordPress API for removing a schedule hook from CRON.
	 */
	function semantria_cron_clear() {
		wp_clear_scheduled_hook( 'semantria_cron_job' );
	}

	/**
	 * Creates a CRON job on the WordPress schedule to execute Semantria
	 * taxonomy and term processing.
	 *
	 * @uses wp_next_scheduled() WordPress API for getting the next scheduled instance of a schedule hook.
	 * @uses wp_schedule_event() WordPress API for scheduling a new instance of a schedule hook.
	 */
	function semantria_cron_create() {
		if ( false === wp_next_scheduled( 'semantria_cron_job' ) ) {
			wp_schedule_event( time(), 'semantria_five_mins', 'semantria_cron_job' );
		}
	}

	/**
	 * Retrieves the Semantria supplied information from the Queue table
	 * for use in evaluation or taxonomy and term creation.  The data is
	 * restored to it's object state and not returned as a String.
	 * 
	 * @global object $wpdb The WordPress Database object.
	 * @param string $semantria_queue_id The Semantria Queue identifier used to retrieve the data record.
	 */
	function semantria_get_data( $semantria_queue_id ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'semantria_queue';
        $data = $wpdb->get_var( $wpdb->prepare( "SELECT semantria_data FROM $table_name WHERE semantria_id = %s", $semantria_queue_id ) );
        
        if ( $data == null ) {
            return null;
        }
        
		return unserialize( $data );
    }

	/**
	 * Connects to the Semantria servers to retrieve a single document
	 * which was sent for analysis.
	 * 
	 * @global $semantria_session Semantria PHP Wrapper Session object.
	 * @param int $post_id WordPress Post ID.
	 * @param string $semantria_queue_id Semantria Queue ID for making the API call to Semantria.
	 * @uses semantria_process_terms A WordPress-Semantria plugin API call to take the processed entities and add them to the post.
	 */
	function semantria_get_document( $post_id, $semantria_queue_id, $type = 'document' ) {
		global $semantria_session, $wpdb;
		
		if ( $semantria_queue_id !== '' ) {
			if ( $type == 'document' ) {
				$data = $semantria_session->getDocument( $semantria_queue_id );
			}
			else {
				$data = $semantria_session->getCollection( $semantria_queue_id );
			}
			
			$wpdb->update(
				$wpdb->prefix . 'semantria_queue',
				array(
					'semantria_data' => serialize( $data ),
					'status' => 'processing'
				),
				array(
					'semantria_id' => $semantria_queue_id
				)
			);
			
			if ( array_key_exists( 'entities', $data ) && empty( $data['entities'] ) == false ) {
				foreach ( $data['entities'] as $entity ) {
					/**
					 * Safety check - make sure that the Entity Type field is present
					 * so that we can actually use it to create the taxonomy.  Also we
					 * make sure that Quote is filtered out as it's not really suited
					 * as a taxonomy.
					 */
					if ( array_key_exists( 'entity_type', $entity ) && 'quote' !== strtolower( $entity['entity_type'] ) ) {
						semantria_create_taxonomy( $entity['entity_type'] );
					}
				}
			}
		}
	}

	/**
	 * Retrieve the specified record from the Semantria Queue table.
	 *
	 * @global object $wpdb The WordPress Database object.
	 * @param string $semantria_queue_id Semantria Queue ID to search for in the Queue table.
	 */
	function semantria_get_queue_item( $semantria_queue_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'semantria_queue';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE semantria_id = %s", $semantria_queue_id ) );
	}

	/**
	 * Retrieve the number of Posts and Pages within this current 
	 * installation which are not on the Semantria Queue.
	 *
	 * @global object $wpdb The WordPress Database object.
	 */
	function semantria_get_unprocessed_post_count() {
		global $wpdb;

		$semantria_queue_table = $wpdb->prefix . 'semantria_queue';
		return $wpdb->get_var( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_status LIKE 'publish' AND post_type IN('post', 'page') AND post_content != '' AND ID NOT IN(SELECT post_id FROM $semantria_queue_table) ORDER BY ID" );
	}

	/**
	 * Retrieve a list of post ids of Posts and Pages which are not
	 * currently on the Semantria Queue.  Used primarily to identify
	 * which Posts are to be added and then process on the Queue.
	 *
	 * @global object $wpdb The WordPress Database object.
	 * @param int $offset The starting record to be retrieved.
	 * @param int $count The number of records after the starting record to be retrieved.
	 */
	function semantria_get_unprocessed_post_ids( $count ) {
		global $wpdb;

		$semantria_queue_table = $wpdb->prefix . 'semantria_queue';
		return $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_status LIKE 'publish' AND post_type IN('post', 'page') AND post_content != '' AND ID NOT IN(SELECT post_id FROM $semantria_queue_table) ORDER BY ID LIMIT 0, %d", $count ) );
	}

	/**
	 * A function to determine if the document date for the Semantria 
	 * Queue item has past 24 hours.  If it has TRUE is returned as the
	 * Semantria API will error.  Under 24 hours, this function will
	 * return FALSE meaning it is safe to continue with the call to
	 * Semantria.
	 * 
	 * @param string Semantria Queue Item added (creation) date.
	 */
	function semantria_has_expired( $document_date ) {
		$item_date = new DateTime( $document_date );
		$now = new DateTime();
		
		return ( 1 <= $item_date->diff( $now )->d );
	}

	/**
	 * Takes a Queued record and processes the document to build the
	 * taxonomy and terms from Semantria and then marks the Queued
	 * record as "completed".
	 *
	 * @global object $wpdb The WordPress Database object.
	 * @param int $post_id WordPress Post ID.
	 * @param string $semantria_queue_id Semantria Queue ID for making the API call to Semantria.
	 * @uses semantria_get_data() Retrieves the Semantria data from the Queue record.
	 * @uses semantria_process_document_data() Takes the Semantria data and creates the taxonomies and terms.
	 * @uses semantria_queue_complete() Marks the Queue record as "Completed".
	 */
	function semantria_process_document( $post_id, $semantria_queue_id ) {
		global $wpdb;
		
        $data = semantria_get_data( $semantria_queue_id );
        
        if ( empty( $data ) === false ) {
            semantria_process_document_data( $post_id, $data );
            semantria_queue_complete( $semantria_queue_id );
        }
	}
    
    /**
     * Takes the Semantria data and processes the "themes" which are
     * to be turned into WordPress tags and processes "entities"
     * which are turned into new taxonomies and terms.
     *
     * @param int $post_id WordPress Post ID.
     * @param object $data Unserialise JSON structure from Semantria API.
     * @uses semantria_process_terms() Takes a set of terms from the Semantria data to create the relevant taxonomies.
     */
    function semantria_process_document_data( $post_id, $data ) {
        if ( array_key_exists( 'themes', $data ) && empty( $data['themes'] ) == false ) {
            semantria_process_terms( $post_id, $data['themes'], true );
        }
        
        if ( array_key_exists( 'entities', $data ) && empty( $data['entities'] ) == false ) {
            semantria_process_terms( $post_id, $data['entities'], false );
        }
    }
	
	/**
	 * Takes each Queue record marked as "processing" and actually
	 * performs the processing of the Queue data.  Used by the CRON
	 * job primarily.
	 * 
	 * @global object $wpdb The WordPress Database object.
	 * @uses semantria_process_document() For a specific Queue record and Post ID, creates the taxonomies and terms from the Semantria API response.
	 */
	function semantria_process_queue() {
		global $wpdb;
		
		$queue_table = $wpdb->prefix . 'semantria_queue';
		$data = $wpdb->get_results( "SELECT qt.post_id, qt.semantria_id FROM $queue_table qt WHERE qt.status = 'processing' ORDER BY added LIMIT 0, 400" );
		
		foreach ( $data as $item ) {
			semantria_process_document( $item->post_id, $item->semantria_id );
		}
	}
	
	/**
	 * Grunt-work function which takes a set of terms and taxonomies
	 * from Semantria analysis and adds them to work (if required)
	 * and connects them to WordPress Post.
	 * 
	 * @uses object Inflector To pluralise the name of the taxonomy for checks.
	 * @uses semantria_create_taxonomy WordPress-Semantria API call to create new taxonomies if needed.
	 * @uses wp_insert_term WordPress API call to insert new taxonomy terms if needed.
	 * @uses is_object_in_term WordPress API call to see if a term is already associated with the Post.
	 * @uses semantria_set_post_term WordPress-Semantria API call to associated terms with the Post.
	 */
	function semantria_process_terms( $post_id, $data, $is_tag = false ) {
		global $wp_taxonomies;
		$taxonomy = '';
		$inflector = new Inflector();
		
		foreach ( $data as $entity ) {
			if ( array_key_exists( 'entity_type', $entity ) || $is_tag ) {
				
				if ( $is_tag == false && strtolower( $entity['entity_type'] ) == 'quote' ) {
					continue;
				}
				
				if ( $is_tag ) {
					$taxonomy = 'post_tag';
				}
				else {
					$taxonomy = semantria_taxonomy_name( $inflector->pluralize( $entity['entity_type'] ) );
				}
				
				$term = term_exists( $entity['title'], $taxonomy );
				
				if ( $term === 0 || $term === null ) {
					wp_insert_term( $entity['title'], $taxonomy );
				}
				
				/**
				 * Next check is to make sure the term is not already against the Post
				 * and if it's not, then to add it as a tag to the Post.
				 */
				if ( is_object_in_term( $post_id, $taxonomy, $entity['title'] ) == false ) {
					semantria_set_post_term( $post_id, $entity['title'], $taxonomy, $entity['sentiment_score'] );
				}
			}
		}
		
		do_action( 'semantria_process_terms_complete', $post_id, $data );
	}

	/**
	 * Updates the Semantria Queue table to tell us that a processed
	 * document has been retrieved and the Entities assigned within
	 * WordPress.
	 * 
	 * @global object $wpdb The WordPress Database object.
	 * @param string $semantria_id The Queue ID provided in the call to Semantria.
	 * @uses do_action() Calls 'semnatria_queue_complete' hook updated the Semantria Queue record.
	 */
	function semantria_queue_complete( $semantria_id, $status = 'complete' ) {
		global $wpdb;
		
		$now = new DateTime();
		
		$wpdb->update(
			$wpdb->prefix . 'semantria_queue',
			array(
				'closed' => $now->format( 'Y-m-d H:i:s' ),
				'status' => $status
			),
			array(
				'semantria_id' => $semantria_id
			)
		);
		
		do_action( 'semnatria_queue_complete', $semantria_id );
	}
	
	/**
	 * Updates the Semantria Queue table to tell us that the specified
	 * item on the queue has expired (exceeded the 24-hours from it's
	 * initial send to the Semantria's web service).
	 * 
	 * @uses semantria_queue_complete Performs the actual database UPDATE statement, as this is an type of "complete" status.
	 */
	function semantria_queue_expire( $semantria_id ) {
		semantria_queue_complete( $semantria_id, 'expired' );
	}

	/**
	 * To be used in place of wp_set_post_term() as this method provides a
	 * mechanism to add in sentiment value.  Note that this method treats
	 * new terms as additions instead of replacements.
	 * 
	 * @param int $post_id WordPress Post ID.
	 * @param string $term New term to associate with the given Post ID.
	 * @param string $taxonomy The Taxonomy from which the term originates.
	 * @param float $sentiment Semantria's sentiment analysis of the word in context of the article.
	 */
	function semantria_set_post_term( $post_id, $term, $taxonomy, $sentiment ) {
		$term_id = -1;
		
		if ( $taxonomy == 'post_tag' ) {
			$set_term_result = wp_set_post_terms( $post_id, $term, $taxonomy, true );
			$term_id = $set_term_result[0];
		}
		else {
			$term_details = get_term_by( 'name', $term, $taxonomy );
			$set_term_result = wp_set_post_terms( $post_id, $term_details->name, $taxonomy, true );
			$term_id = $set_term_result[0];
		}
		
		if ( $set_term_result !== false ) {
			semantria_set_post_term_insert( $post_id, $term_id, $sentiment );
		}
	}

	/**
	 * Performs the parallel insert on the Term Relationships table within
	 * WordPress into the Semantria version so we can store Sentiment score
	 * and other useful information later on.
	 * 
	 * @global object $wpdb The WordPress Database object.
	 * @param string $post_id WordPress Post ID
	 * @param string $term_id WordPress Term ID.
	 * @param float $sentiment A number which indicates the positive, negative or neutrality of the term in relation to the document.
	 */
	function semantria_set_post_term_insert( $post_id, $term_id, $sentiment ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'term_relationships_semantria',
			array(
				'object_id' => $post_id,
				'term_taxonomy_id' => $term_id,
				'sentiment' => $sentiment
			)
		);
	}
	
	/**
     * Used to validate if the provided string is a valid Status
     * type for the Semantria plugin.
     * 
     * @param string $status String representation of an Semantria post status type.
     */
    function semantria_status_is_valid( $status ) {
        return in_array( $status, array( 'processing', 'queued', 'complete', 'stopped', 'expired', 'requeue' ) );
    }

    /**
     * Checks to see if a specific Taxonomy exists within the Semantria
     * taxonomy table.  This is to ensure there are no duplicates when
     * the plugin creates the WordPress taxonomies.
     * 
     * @global object $wpdb The WordPress Database object.
	 * @param string $name The name of the taxonomy to check in the database.
     */
	function semantria_taxonomy_exists( $name ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'semantria_taxonomy';
		$count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(semantria_taxonomy_id) FROM $table_name WHERE name = %s", $name ) ) );
		
		return $count > 0;
	}

	/**
	 * Takes a Semantria Taxonomy Name and generates a friendly name
	 * to be used as the taxonomy slug.
	 * 
	 * @param string $taxonomy_friendly_name_plural Plural version of the taxonomy name (Entity Type in Semantria parlance).
	 */
	function semantria_taxonomy_name( $taxonomy_friendly_name_plural ) {
		$slug = 'semantria-' . str_replace( ' ', '', strtolower( $taxonomy_friendly_name_plural ) );
		return apply_filters( 'semantria_taxonomy_name', $slug );
	}

?>