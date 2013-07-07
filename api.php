<?php

	function semantria_taxonomy_name( $taxonomy_friendly_name_plural ) {
		return 'semantria-' . str_replace( ' ', '', strtolower( $taxonomy_friendly_name_plural ) );
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
	
	function semantria_taxonomy_exists( $name ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'semantria_taxonomy';
		$count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(semantria_taxonomy_id) FROM $table_name WHERE name = %s", $name ) ) );
		
		return $count > 0;
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
		global $wpdb;
		
		$term_id = -1;
		
		if ( $taxonomy == 'post_tag' ) {
			$set_term_result = wp_set_post_terms( $post_id, $term, $taxonomy, true );
			$term_id = $set_term_result[0];
		}
		else {
			$term_details = get_term_by( 'name', $term, $taxonomy );
			$set_term_result = wp_set_post_terms( $post_id, $term_details->term_id, $taxonomy, true );
			$term_id = $term_details->term_id;
		}
		
		if ( $set_term_result !== false ) {
			$wpdb->insert(
				$wpdb->prefix . 'term_relationships_semantria',
				array(
					'object_id' => $post_id,
					'term_taxonomy_id' => $term_id,
					'sentiment' => $sentiment
				)
			);
		}
	}
	
	/**
	 * Adds a new record on the Semantria Queue table which will be used
	 * by the Cron job to retrieve the analysis later on once Semantria is
	 * finished.
	 * 
	 * @global object $wpdb The WordPress Database object.
	 * @param string $semantria_id The Queue ID provided in the call to Semantria.
	 * @param string $type Determines if the Semantria ID is for a Semantria Document or Collection.
	 */
	function semantria_add_queue( $semantria_id, $type ) {
		global $wpdb;
		
		$now = new DateTime();
		
		$wpdb->insert(
			$wpdb->prefix . 'semantria_queue',
			array(
				'semantria_id' => $semantria_id,
				'added' => $now->format( 'Y-m-d H:i:s' ),
				'closed' => null,
				'status' => 'queued',
				'type' => $type
			)
		);
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
	function semantria_queue_complete( $semantria_id ) {
		global $wpdb;
		
		$now = new DateTime();
		
		$wpdb->update(
			$wpdb->prefix . 'semantria_queue',
			array(
				'closed' => $now->format( 'Y-m-d H:i:s' ),
				'status' => 'complete'
			),
			array(
				'semantria_id' => $semantria_id
			)
		);
		
		do_action( 'semnatria_queue_complete', $semantria_id );
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
					if ( array_key_exists( 'entity_type', $entity ) ) {
						semantria_create_taxonomy( $entity['entity_type'] );
					}
				}
			}
		}
	}
    
    function semantria_get_queue_item( $semantria_queue_id ) {
        global $wpdb;
        
        
    }
	
	function semantria_process_document( $post_id, $semantria_queue_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'semantria_queue';
		$data = semantria_get_data( $semantria_queue_id );
		
		if ( empty( $data ) == false ) {
			if ( array_key_exists( 'themes', $data ) && empty( $data['themes'] ) == false ) {
				semantria_process_terms( $post_id, $data['themes'], true );
			}
			
			if ( array_key_exists( 'entities', $data ) && empty( $data['entities'] ) == false ) {
				semantria_process_terms( $post_id, $data['entities'], false );
			}
			
			semantria_queue_complete( $semantria_queue_id );
		}
	}
	
	function semantria_process_queue() {
		global $wpdb;
		
		$queue_table = $wpdb->prefix . 'semantria_queue';
		$data = $wpdb->get_results( "SELECT pm.post_id, qt.semantria_id FROM $queue_table qt INNER JOIN $wpdb->postmeta pm ON qt.semantria_id = pm.meta_value WHERE qt.status = 'processing' ORDER BY added LIMIT 0, 400" );
		
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
					update_post_meta( $post_id, 'semantria_quote', $entity['title'] );
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
		
		if ( array_key_exists( 'documents', $document ) ) {
			$status = $semantria_session->queueCollection( $document );
			$queue_type = 'collection';
		}
		else {
			$status = $semantria_session->queueDocument( $document );
		}
		
		if ( $status == 202 ) {
			semantria_add_queue( $document['id'], $queue_type );
			update_post_meta( $post_id, 'semantria_queue_id', $document['id'] );
			
			return $document['id'];
		}
		else {
			return null;
		}
	}
    
    function semantria_status_is_valid( $status ) {
        return in_array( $status, array( 'processing', 'queued', 'complete', 'stopped' ) );
    }

?>