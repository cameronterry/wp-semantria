    
    var wpsemantria = {
        modal : {
            current : {
                data : null
            },
            bind : function ( json ) {
                wpsemantria.modal.current.data = json;
                
                /**
                 * Get the basic article onto the screen.
                 */
                var Template = Handlebars.compile( '<h3>{{title}}</h3>{{{body}}}' );
                jQuery( 'div[rel="post"]', '#pnlSemantriaModal' ).html( Template( json.article ) ).fadeIn();
                
                /**
                 * Now we grab the bits of Semantria which are used in the WordPress plugin
                 * so that we can display them to the user.
                 */
                if ( json.entities ) {
                    wpsemantria.modal.bind_option( { description : 'Hover over to view the terms in the text.', item_title : 'Entities', item_type : 'entity', items : json.entities } );
                    
                    //jQuery( 'div[rel="options"] input[name="entity[]"][type="checkbox"]', '#pnlSemantriaModal' ).change( function () {} );
                    jQuery( 'div[rel="options"] > ul[rel="entity"] label', '#pnlSemantriaModal' ).hover( function () {}, function () {} );
                }
                
                if ( json.themes ) {
                    wpsemantria.modal.bind_option( { item_title : 'Themes (Tags)', item_type : 'theme', items : json.themes } );
                }
                
                wpsemantria.modal.select_options();
                console.log( json );
            },
            bind_option : function ( data ) {
                var Template = Handlebars.compile( '<h4>{{item_title}}</h4>{{#if description}}<div>{{description}}</div>{{/if}}<ul rel="{{item_type}}">{{#items}}<li><label><input name="{{../item_type}}[]" data-title="{{title}}" type="checkbox" value="{{@index}}" />&nbsp;&nbsp;&nbsp;{{title}} {{#if label}}({{label}}){{/if}}</label></li>{{/items}}</ul>' );
                jQuery( 'div[rel="options"]', '#pnlSemantriaModal' ).append( Template( data ) );
            },
            hide : function () {
                jQuery( '#pnlSemantriaBackdrop' ).fadeOut();
                jQuery( '#pnlSemantriaModal' ).fadeOut();
            },
            reset : function () {
                jQuery( 'div[rel="post"]', '#pnlSemantriaModal' ).css( 'display', 'none' ).html( '' );
                jQuery( 'div[rel="options"]', '#pnlSemantriaModal' ).html( '' );
                
                jQuery( '#pnlLoading', '#pnlSemantriaModal' ).css( 'display', 'block' );
            },
            save : function () {
                /**
                 * Perform a deep copy of the current data retrieved from the Semantria
                 * Queue at the start.  Not the most efficient mechanism but I'm stumped
                 * for a better alternative for now.
                 */
                var selected = jQuery.extend( true, {}, wpsemantria.modal.current.data );
                
                /**
                 * Filter out the Entities if there is any.
                 */
                if ( selected.entities ) {
                    selected.entities = jQuery.grep( selected.entities, function ( obj, index ) {
                        return jQuery( 'div[rel="options"] input[name="entity[]"][type="checkbox"][value="' + index + '"]', '#pnlSemantriaModal' ).prop( 'checked' );
                    } );
                    
                    selected.entities_remove = jQuery.grep( selected.entities, function ( obj, index ) {
                        return jQuery( 'div[rel="options"] input[name="entity[]"][type="checkbox"][value="' + index + '"]', '#pnlSemantriaModal' ).prop( 'checked' ) === false;
                    } );
                }
                
                /**
                 * Filter out the Themes if there is any.
                 */
                if ( selected.themes ) {
                    selected.themes = jQuery.grep( selected.themes, function ( obj, index ) {
                        return jQuery( 'div[rel="options"] input[name="theme[]"][type="checkbox"][value="' + index + '"]', '#pnlSemantriaModal' ).prop( 'checked' );
                    } );
                    
                    selected.themes_remove = jQuery.grep( selected.themes, function ( obj, index ) {
                        return jQuery( 'div[rel="options"] input[name="theme[]"][type="checkbox"][value="' + index + '"]', '#pnlSemantriaModal' ).prop( 'checked' ) === false;
                    } );
                }
                
                jQuery.post(
                    ajaxurl,
                    {
                        action: 'wp_semantria_save',
                        data : selected,
                        security : jQuery( '#hdnSaveNonce', '.wrap.wp-semantria-content' ).val()
                    },
                    function ( data ) {
                        wpsemantria.modal.hide();
                        wpsemantria.modal.reset();
                    },
                    'text'
                );
            },
            select_options : function () {
                var terms = wpsemantria.modal.current.data.article.terms;
                
                if ( terms.semantria ) {
                    for ( var i = 0; i < terms.semantria.length; ++i ) {
                        jQuery( 'input[type="checkbox"][data-title="' + terms.semantria[i].name + '"]', 'div.semantria-modal-content > .options > .inner' ).prop( 'checked', true );
                    }
                }
                
                if ( terms.post_tag ) {
                    for ( var i = 0; i < terms.post_tag.length; ++i ) {
                        jQuery( 'input[type="checkbox"][data-title="' + terms.post_tag[i].name + '"]', 'div.semantria-modal-content > .options > .inner' ).prop( 'checked', true );
                    }
                }
            },
            show : function () {
                jQuery( '#pnlSemantriaBackdrop' ).fadeIn();
                jQuery( '#pnlSemantriaModal' ).fadeIn();
                
                wpsemantria.modal.reset();
            }
        },
        get : function ( post_id, semantria_id ) {
            wpsemantria.modal.show();
            
            jQuery.post(
				ajaxurl,
				{
                    action: 'wp_semantria_get',
                    semantria_id : semantria_id,
                    post_id : post_id
                },
				wpsemantria.modal.bind,
				'json'
			);
        },
        update_status : function ( post_id, semantria_id, status ) {
            var $row = jQuery( '#row-' + post_id );
            var $post_title = jQuery( 'strong', $row );
            var original_text = $post_title.text();
            
            $post_title.text( 'Processing ...' );
            
            jQuery.post(
				ajaxurl,
				{
                    action: 'wp_semantria_update_status',
                    semantria_id : semantria_id,
                    post_id : post_id,
                    new_status : status
                },
				function ( status ) {
                    if ( status == 'done' ) {
                        setTimeout( function () { $row.fadeOut( 'slow' ); }, 250 );
                    }
                    else {
                        $post_title.text( original_text );
                    }
                },
				'text'
			);
        }
    };
    
    function wp_semantria_ajax_handler( data ) {
		if ( data == 'finished' ) {
			window.location.href = window.location.href;
			return;
		}
		else {
			var offset = parseInt( data, 10);
			
			if ( isNaN( offset ) ) {
				jQuery( '#ltlCurrentPosition' ).text( 'ERROR!' );
				return;
			}
			
			jQuery( '#ltlCurrentPosition' ).text( offset );
			
			jQuery.post(
				ajaxurl,
				{
					action: 'wp_semantria_ingest_all',
					offset: offset
				},
				wp_semantria_ajax_handler,
				'text'
			);
		}
	}

	( function ( $ ) {
		jQuery( document ).ready( function () { 
			jQuery( '#cmdPerformDataIngestion' ).click( function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				
				jQuery( this ).attr( 'disabled', 'disabled' );
				
				wp_semantria_ajax_handler( 0 );
			} );
            
            $( 'a.evaluate', '.wp-semantria-content > .wp-semantria-table' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
				
                var $this = $( this );
                
                wpsemantria.get( $this.data( 'post-id'), $this.data( 'semantria-id' ) );
            } );
            
            $( 'a.update-status', '.wp-semantria-content > .wp-semantria-table' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
				
                var $this = $( this );
                
                wpsemantria.update_status( $this.data( 'post-id'), $this.data( 'semantria-id' ), $this.data( 'next-status' ) );
            } );
            
            $( 'button.close-modal', '#pnlSemantriaModal' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
                
                wpsemantria.modal.hide();
            } );
            
            $( 'button.save-modal', '#pnlSemantriaModal' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
                
                wpsemantria.modal.save();
            } );
		} );
	} )( jQuery );
    