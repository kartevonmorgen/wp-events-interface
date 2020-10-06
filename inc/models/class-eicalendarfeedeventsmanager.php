<?php
if ( ! class_exists( 'EICalendarFeedEventsManager' ) ) 
{

/**
  * EICalendarFeedEventsManager
  * Read events from the Events Manager as an array
  * of EICalendarEvent objects.
  * Save EICalendarEvent objects into the Events Manager
  * The EICalendarEvent Object contains all the information
  * about an event. So the 
  *
  * @author     Sjoerd Takken
  * @copyright  No Copyright.
  * @license    GNU/GPLv2, see https://www.gnu.org/licenses/gpl-2.0.html
  */
class EICalendarFeedEventsManager extends EICalendarFeed 
{
  /** 
   * Add a listener when an event is saved.
   * Siehe https://wp-events-plugin.com/
   *               tutorials/saving-custom-event-information/
   *
   * @param listener EIEventSavedListenerIF
   */
  public function add_event_saved_listener($listener)
  {
    parent::add_event_saved_listener($listener);

    if ( !has_filter( 'em_event_save', array( $this, 'em_event_saved' ) ))
    {
      add_filter( 'em_event_save', array( $this, 'em_event_saved' ), 10, 2 );
    }
  }

  public function em_event_saved($result=NULL, $event=NULL)
  {
    if ( $result == TRUE )
    {
      $event_id = $event;
      $this->fire_event_saved($event_id);
    }
  }

  /** 
   * Add a listener when an event is deleted.
   * Siehe https://wp-events-plugin.com/
   *               tutorials/saving-custom-event-information/
   *
   * @param listener EIEventDeletedListenerIF
   */
  public function add_event_deleted_listener($listener)
  {
    parent::add_event_deleted_listener($listener);

    if ( !has_filter( 'em_event_delete', array( $this, 'em_event_deleted' ) ))
    {
      add_filter( 'em_event_delete', array( $this, 'em_event_deleted' ), 10 , 2 );
      add_action('trashed_post', array( $this, 'em_event_trashed' ) );
    }
  }

  public function em_event_trashed($post_id)
  {
    $event = em_get_event($post_id,'post_id');
    if(empty($event))
    {
      // It is not an Event
      return;
    }
    $this->em_event_deleted(true, $event);
  }

  public function em_event_deleted($result=NULL, $event)
  {
    if ( $result == true )
    {
      $event_id = $event->event_id;
      $this->fire_event_deleted($event_id);
    }
  }

  public function init()
  {
    if ( !has_action( 'em_location_save_pre', 
          array( $this, 'em_location_save_pre' )))
    {
      add_action( 'em_location_save_pre', 
                  array( $this, 'em_location_save_pre' ));
    }
  }

  /**
   * If a location is saved, then we try to fill the longitude
   * and latitude by osm_nominatim()
   */
  public function em_location_save_pre($emLocation)
  {
    // Only fill if they are not filed, because a HTTP request
    // to OSM is expensive.
    if(empty($emLocation))    
    {
      return;
    }

    if(!get_option('ei_fill_lanlon_coordinates_over_osm', false))
    {
      return;
    }

    $eiEventLocation = $this->get_ei_event_location($emLocation);
    if(empty($eiEventLocation))
    {
      return;
    }

    $wpLocationHelper = new WPLocationHelper();
    $eiEventLocation = 
      $wpLocationHelper->fill_by_osm_nominatim(
        $eiEventLocation);
    $this->fill_em_location($emLocation, $eiEventLocation);
  }

  private function get_ei_event_location($emLocation)
  {
    if(empty($emLocation))
    {
      return null;
    }

    $eiEventLocation = new WPLocation();
    $wpLocH = new WPLocationHelper();
    $wpLocH->set_name($eiEventLocation, $emLocation->location_name);

    $wpLocH->set_address( $eiEventLocation, 
                          $emLocation->location_address );
    $wpLocH->set_city( $eiEventLocation, 
                       $emLocation->location_town );
    $wpLocH->set_state( $eiEventLocation, 
                        $emLocation->location_state );
    $wpLocH->set_zip( $eiEventLocation, 
                      $emLocation->location_postcode );
    $wpLocH->set_country_code( $eiEventLocation, 
                               $emLocation->location_country );
    $eiEventLocation->set_lon( $emLocation->location_longitude );
    $eiEventLocation->set_lat( $emLocation->location_latitude );

    if($wpLocH->is_location_empty($eiEventLocation))
    {
      return null;
    }
    return $eiEventLocation;
  }

  /**
   * Delete the underlying EICalendarEvent object 
   * for a determinated event_id.
   *
   * @param $event_id int: should be the eiEvent->get_event_id()
   */
  public function delete_event_by_event_id( $event_id )
  {
    if(empty($event_id))
    {
      return;
    }

	  $event = em_get_event( $event_id );
    if(empty($event))
    {
      return;
    }

    echo 'Delete'. $event_id;

    $force_delete = get_option('ei_delete_permanently', false);
    $event->delete($force_delete);
  }

  /**
   * Retrieve the EICalendarEvent object for a determinated
   * event_id, which corresponds with the event_id of
   * the EM_Event Object in the Events Manager.
   *
   * @param $event_id int
   * @return EICalendarEvent
   */
  public function get_event_by_event_id( $event_id ) 
  {
    if(empty($event_id))
    {
      return null;
    }

	  $event = em_get_event( $event_id );
    if(empty($event))
    {
      return null;
    }

    if($event->post_status === 'trash')
    {
      return null;
    }

	  $post = get_post( $event->post_id );
    $eiEvent = $this->convert_to_eievent($post, $event);
    return $eiEvent;
  }

  public function get_event_by_uid($uid)
  {
    $args = array(
      'name'        => $uid,
      'post_type'   => EM_POST_TYPE_EVENT,
      'post_status' => array('draft', 'pending', 'publish'),
      'numberposts' => 1);
    $em_posts = get_posts($args);
    if( empty( $em_posts )) 
    {
      return null;
    }
    
    $em_post = reset($em_posts);
    if( empty( $em_post ))
    {
      return null;
    }
    $em_event = em_get_event($em_post->ID, 'post_id');
    return $this->convert_to_eievent($em_post, $em_event);
  }

  /**
   * Retrieve the EICalendarEvent objects for a determinated
   * Time range.
   *
   * @param $start_date int
   * @param $end_date int
   * @param $event_cat String: is the slug of the Event Category
   * @return EICalendarEvent[]
   */
  public function get_events( $start_date, $end_date, $event_cat=NULL ) 
  {
    $retval = array();

    $event_cat = str_replace(',','&', $event_cat);

    $filters = array(
		   'category' => $event_cat,
       'status' => 'publish',
		   'tag' => array(),
		   'scope' => date( 'Y-m-d', $start_date ) . ',' . date( 'Y-m-d', $end_date + 86400 ),
	    );
	  $event_results = EM_Events::get( apply_filters( 'ei_fetch_events_args-' . $this->get_identifier(), $filters ));

    foreach ( $event_results as $event ) 
    {
	    $post = get_post( $event->post_id );
      $eiEvent = $this->convert_to_eievent($post, $event);
      if( empty( $eiEvent ) )
      {
        continue;
      }
      $retval[] = $eiEvent;
    }
    return $retval;
  }


  /**
   * Converts the Events Manager Event Type and Post
   * into an EICalendarEvent.
   *
   * @param $post array: contains the Post Object of the Event
   * @param $event EM_Event: contains the EM_Event Object 
   *                         of the Events Manager
   * @return EICalendarEvent
   */
  private function convert_to_eievent($post, $event)
  {
    // HACK um Multiblog zu Unterstutzen
    $permalink = get_the_permalink( $post->ID );
    if ( is_multisite() &&
         $event->blog_id != get_current_blog_id())
    {
      $new_blog = $event->blog_id;
      switch_to_blog($new_blog);
      $post = get_post( $event->post_id );
      $permalink = get_the_permalink( $post->ID );
      restore_current_blog(); 
    }
    // END HACK

    $emCategories = new EM_Categories($event);
    $categories = $emCategories->terms; 

    $emTags = new EM_Tags($event);
    $tags = $emTags->terms;

	  $location = $event->get_location();
    $image_src = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), apply_filters( 'ei_image_size', 'medium' ) );
    if ( !empty( $image_src ) )
    {
      $image_url = $image_src[0];
    }
    else
    {
      $image_url = false;
    }

    $event_start_datetime = strtotime( 
      $event->event_start_date . ' ' . $event->event_start_time );
    $event_end_datetime = strtotime( 
      $event->event_end_date . ' ' . $event->event_end_time );

    //global $wp_taxonomies;
    //var_dump($wp_taxonomies);
    //die;



    $eiEvent = new EICalendarEvent();
    $eiEvent->set_slug( $event->event_slug );
    $eiEvent->set_link( $permalink );

    $this->fill_event_by_post($post, $eiEvent);

    $eiEvent->set_event_id( $event->event_id );
    $eiEvent->set_blog_id( $event->blog_id );

		$eiEvent->set_plugin( $this->get_identifier() );

    $eiEvent->set_start_date( $event_start_datetime );
    $eiEvent->set_end_date( $event_end_datetime );
    $eiEvent->set_all_day( $event->event_all_day );
		$eiEvent->set_published_date( $event->post_date );
		$eiEvent->set_updated_date( $event->post_modified );

    $eiEvent->set_location( $this->get_ei_event_location($location));

    $initiative_id = get_the_author_meta('initiative_id',
                                         $event->event_owner);

    $contact_name = get_post_meta($post->ID, 
                                  'contact_name', 
                                   true);
    if(!empty($contact_name))
    {
      $eiEvent->set_contact_name( $contact_name );
    }

    // Email
    $contact_email = get_post_meta($post->ID, 
                                  'contact_email', 
                                   true);
    if(!empty($contact_email))
    {
      $eiEvent->set_contact_email( $contact_email );
    }

    // Telefon
    $contact_phone = get_post_meta($post->ID, 
                                  'contact_phone', 
                                   true);
    if(!empty($contact_phone))
    {
      $eiEvent->set_contact_phone( $contact_phone );
    }

    // Website
    $contact_website = get_post_meta($post->ID, 
                                  'contact_website', 
                                   true);
    if(!empty($contact_website))
    {
      $eiEvent->set_contact_website( $contact_website );
    }
    
    $eiEvent->set_event_image_url( $image_url );

		$eiEvent->set_categories( 
      WPCategory::create_categories($categories));
    $eiEvent->set_tags( WPTag::create_tags($tags));

    return $eiEvent;
  }


  /**
   * Save the EICalendarEvent object into the Events Manager
   *
   * @param $eiEvent EICalendarEvent
   * @return EICalendarEventSaveResult: Result of the saving action.
   */
  public function save_event($eiEvent)
  {
    $is_new = true;
    $result = new EICalendarEventSaveResult();
    $emEvent = null;

    try
    {
      // suppress save events, because we do 
      // multiple save actions here.
      // afterwards we enable it again and
      // fire the fire_event_saved(..)
      $this->set_suppress_event_saved(true);

      $args = array(
        'name'        => $eiEvent->get_uid(),
        'post_type'   => EM_POST_TYPE_EVENT,
        'post_status' => array('draft', 'pending', 'publish'),
        'numberposts' => 1);
      $em_posts = get_posts($args);
      if( !empty( $em_posts )) 
      {
        $em_post = reset($em_posts);
        if( ! empty( $em_post ))
        {
          $emEvent = em_get_event($em_post->ID, 'post_id');
          $is_new = false;
        }
      }

      if($is_new)
      {
        $emEvent = new EM_Event();
      }

      if( !empty($eiEvent->get_owner_user_id()))
      {
        $emEvent->event_owner = $eiEvent->get_owner_user_id();
        $emEvent->owner = $eiEvent->get_owner_user_id();
      }
    
      // user must have permissions.
      if ( ! $emEvent->can_manage( 'edit_events', 
                                   'edit_other_events',
                                   $eiEvent->get_owner_user_id()))
      {
        $result->set_error("No Permission for the current user to save events");
        return $result;
      }

      if( $is_new )
      {
        // It is an new Event
        $emEvent->force_status = 'pending';
        if( $emEvent->can_manage( 'publish_events', 
                                  'publish_events',
                                  $eiEvent->get_owner_user_id()))
        {
          $emEvent->force_status = 'publish';
        }
      }
      else
      {
        $emEvent->force_status = $emEvent->post_status;
        // the event exists already
        // $emEvent->event_status = 1;
        // $emEvent->previous_status = 1;
      }

      $emEvent->event_name = $eiEvent->get_title();
      if($eiEvent->has_excerpt())
      {
        $emEvent->post_excerpt = $eiEvent->get_excerpt();
      }
      else
      {
        $emEvent->post_excerpt = '';
      }
      $emEvent->post_content = $eiEvent->get_description();

      // Start Date and Time
      $emEvent->event_start_date = date( 'Y-m-d', 
                      strtotime( $eiEvent->get_start_date()));
      $emEvent->event_start_time = date( 'H:i:s', 
                      strtotime( $eiEvent->get_start_date()));
      $emEvent->start = strtotime( $emEvent->event_start_date." ".
                                 $emEvent->event_start_time );

      // End Date and Time
      $emEvent->event_end_date = date( 'Y-m-d', 
                    strtotime( $eiEvent->get_end_date()));
      $emEvent->event_end_time = date( 'H:i:s', 
                    strtotime( $eiEvent->get_end_date()));
      $emEvent->end = strtotime( $emEvent->event_end_date." ".
                                 $emEvent->event_end_time );


      // Save first, so the Relations (Locations, Categories
      // und Tags) have an ID to bind on.
      $emEvent->save();

      $result->set_event_id($emEvent->event_id);
      $result->set_post_id($emEvent->post_id);
    

      if((!empty( $eiEvent->get_location())) && 
         get_option( 'dbem_locations_enabled' ) )
      {
        $emLocation = $this->save_event_location($eiEvent, $result);
        if($result->has_error())
        {
          return $result;
        }
        $emEvent->location_id = $emLocation->location_id;
        $emEvent->location = $emLocation;
      }

      if((!empty( $eiEvent->get_categories())) &&
        get_option('dbem_categories_enabled'))
      {
        $emCategories = $this->save_event_categories($eiEvent, $result);
        if($result->has_error())
        {
          return $result;
        }
        $emEvent->categories = $emCategories;
      }

      if((!empty( $eiEvent->get_tags())) &&
        get_option('dbem_tags_enabled'))
      {
        $emTags = $this->save_event_tags($emEvent, $eiEvent, $result);
        if($result->has_error())
        {
          return $result;
        }
        $emEvent->tags = $emTags;
      }

      $emEvent->save();

      if( $is_new )
      {
        // only update if the event is new
        $post = array(
          'ID' => $emEvent->post_id,
          'post_author' => $eiEvent->get_owner_user_id());
        wp_update_post( $post );
      }

      // == SLUG ist ID from Feed
      // we need to write it here in the 'Event'-Post
      // again, on the previous save method,
      // Events Manager overwrites the event_slug
      $emEvent->event_slug = $eiEvent->get_uid();
      $post = array(
        'ID' => $emEvent->post_id,
        'post_name' => $eiEvent->get_uid());
      wp_update_post( $post );

      update_post_meta($emEvent->post_id, 
                       'contact_name', 
                       $eiEvent->get_contact_name());
      update_post_meta($emEvent->post_id, 
                       'contact_email', 
                       $eiEvent->get_contact_email());
      update_post_meta($emEvent->post_id, 
                       'contact_phone', 
                       $eiEvent->get_contact_phone());
      update_post_meta($emEvent->post_id, 
                       'contact_website', 
                       $eiEvent->get_contact_website());

      // Set the event_id and return the object.
      return $result;
    }
    finally
    {
      // because there where
      // multiple save actions done, we disabled 
      // the fire_event_saved(..) 
      // So we fire it afterwards
      $this->set_suppress_event_saved(false);
      if( !empty($emEvent ))
      {
        $this->fire_event_saved($emEvent->event_id);
      }
    }
  }

  private function save_event_location($eiEvent, $result)
  {
    $eiEventLocation = $eiEvent->get_location();

    $is_new = false;
    $emLocation = $this->find_em_location($eiEventLocation);

    if(empty($emLocation))
    {
      $emLocation = new EM_Location();
      $emLocation->owner = $eiEvent->get_owner_user_id();
      $emLocation->post_content = '';
      $emLocation->post_status = 'publish';
      $emLocation->location_status = 1;
      $is_new = true;
    }

    $this->fill_em_location($emLocation, $eiEventLocation);

    if( !$emLocation->can_manage('publish_locations', 
                                 'publish_locations',
                                 $eiEvent->get_owner_user_id()))
    {
      $result->set_error('No Permission to save an EM_Location');
      return null;
    }

    if ( $emLocation->save() === FALSE )
    {
      $result->set_error( 'SAVE LOCATION ERROR (user='. 
                          get_current_user_id() . ') ' .
                          implode( ",", 
                            $emLocation->get_errors()) );
      return null;
    }

    if($is_new)
    {
      // only update if the event is new
      $post = array(
        'ID' => $emLocation->post_id,
        'post_author' => $eiEvent->get_owner_user_id());
      wp_update_post( $post );
      $emLocation->owner = $eiEvent->get_owner_user_id();
    }
    return $emLocation;
  }

  private function find_em_location($eiEventLocation)
  {
    $wpLocationHelper = new WPLocationHelper();
    $filter = array();
    if(empty($eiEventLocation))
    {
      return null;
    }
    
    if(!empty( $eiEventLocation->get_name()))
    {
      $filters['search'] = $eiEventLocation->get_name();
    }
    if(!empty( $eiEventLocation->get_zip()))
    {
      $filters['postcode'] = $eiEventLocation->get_zip();
    }
    if(!empty( $eiEventLocation->get_country_code()))
    {
      $filters['country'] = $eiEventLocation->get_country_code();
    }
    
    $findEmLocations = array();
    if(!empty( $filters ))
    {
      $findEmLocations = EM_Locations::get($filters);
      if(empty($findEmLocations))
      {
        // If we do not find a location by name, 
        // then we look if the address fits
        $address = $wpLocationHelper->get_address(
                                        $eiEventLocation);
        if(!empty( $address) )
        {
          $filters['search'] = $address;
          $findEmLocations = EM_Locations::get($filters);
        }
      }
    }

    if(empty($findEmLocations))
    {
      return null;
    }
    return reset($findEmLocations);
  }

  private function fill_em_location($emLocation, $eiEventLocation = null)
  {
    if(empty($eiEventLocation))
    {
      return;
    }

    if(!empty($eiEventLocation->get_name()))
    {
      $emLocation->location_name = $eiEventLocation->get_name();
    }

    $wpLocationHelper = new WPLocationHelper();
    $address = $wpLocationHelper->get_address(
                                    $eiEventLocation);
    if(!empty($address))
    {
      $emLocation->location_address = $address;
    }

    if(!empty($eiEventLocation->get_zip()))
    {
      $emLocation->location_postcode = $eiEventLocation->get_zip();
    }

    if(!empty($eiEventLocation->get_city()))
    {
      $emLocation->location_town = $eiEventLocation->get_city();
    }

    if(!empty($eiEventLocation->get_state()))
    {
      $emLocation->location_state = $eiEventLocation->get_state();
    }

    if(!empty($eiEventLocation->get_country_code()))
    {
      $emLocation->location_country = $eiEventLocation->get_country_code();
    }

    if(!empty($eiEventLocation->get_lon()))
    {
      $emLocation->location_longitude = $eiEventLocation->get_lon();
    }

    if(!empty($eiEventLocation->get_lat()))
    {
      $emLocation->location_latitude = $eiEventLocation->get_lat();
    }
  }

  private function save_event_categories($eiEvent, $result)
  {
    $emCategories = new EM_Categories();
    if ( property_exists( $emCategories, 'owner'   ) == FALSE)
    {
      $emCategories->owner = $eiEvent->get_owner_user_id();
    }

    $term_cat_ids = array();
    foreach( $eiEvent->get_categories() as $cat )
    {
      $cat_term = get_term_by( 'slug', 
                               $cat->get_slug(), 
                               EM_TAXONOMY_CATEGORY );
      if ( empty ( $cat_term ))
      {
        if ( $emCategories->can_manage( 'edit_event_categories',
                                        'edit_event_categories',
                                        $eiEvent->get_owner_user_id()))
        {
          // We only add a Category if we have permissions to do
          // (if we can_manage)
          $term_array = wp_insert_term( $cat->get_name(), 
                                   EM_TAXONOMY_CATEGORY,
                                   array( 'slug' => $cat->get_slug(),
                                          'name' => $cat->get_name() ));
          if ( intval( $term_array['term_id'] ) > 0 )
          {
            array_push( $term_cat_ids, intval( $term_array['term_id'] ));
          }
        }
      }
      else
      {
        if ( intval( $cat_term->term_id ) > 0 )
        {
          array_push( $term_cat_ids, intval( $cat_term->term_id ) );
        }
      }
    }

    foreach($term_cat_ids as $term_cat_id)
    {
      $emCategories->terms[$term_cat_id] = new EM_Category($term_cat_id);
    }
    $emCategories->blog_id  = $eiEvent->get_blog_id();
    $emCategories->event_id = $result->get_event_id();
    $emCategories->save(); 
    return $emCategories;
  }

  private function save_event_tags($emEvent, $eiEvent, $result)
  {

    $term_tag_ids = array();
    foreach( $eiEvent->get_tags() as $tag )
    {
      $tag_term = get_term_by( 'slug', 
                                    $tag->get_slug(), 
                                    EM_TAXONOMY_TAG );
      if ( empty ( $tag_term ))
      {
        $term_array = wp_insert_term( $tag->get_name(), 
                                      EM_TAXONOMY_TAG,
                                   array( 'slug' => $tag->get_slug(),
                                          'name' => $tag->get_name() ));
        if ( intval( $term_array['term_id'] ) > 0 )
        {
          array_push( $term_tag_ids, intval( $term_array['term_id'] ));
        }
      }
      else
      {
        if ( intval( $tag_term->term_id ) > 0 )
        {
          array_push( $term_tag_ids, intval( $tag_term->term_id ) );
        }
      }
    }
    
    $emTags = new EM_Tags($emEvent);
    if ( property_exists( $emTags, 'owner'   ) == FALSE)
    {
      $emTags->owner = $eiEvent->get_owner_user_id();
    }
    $emTags->blog_id  = $eiEvent->get_blog_id();
    $emTags->event_id = $result->get_event_id();
    $emTags->terms = array();
    foreach($term_tag_ids as $term_tag_id)
    {
      $emTags->terms[$term_tag_id] = new EM_Tag($term_tag_id);
    }

    $emTags->save(); 
    return $emTags;
  }

  public function register_for_kartevonmorgen()
  {
    add_action('add_meta_boxes', 
               array($this, 'kvm_meta_boxes'));
  }
  
  function kvm_meta_boxes()
  {
      add_meta_box('event_kvm_log', 
                   'Karte von Morgen Log', 
                   array($this,
                         'event_kvm_log_metabox_callback'),
                   EM_POST_TYPE_EVENT, 
                   'side',
                   'low');
      add_meta_box('kvm_event_id', 
                   'Karte von Morgen Id', 
                   array($this,
                         'kvm_event_id_metabox_callback'),
                   EM_POST_TYPE_EVENT, 
                   'side',
                   'low');
//      add_meta_box('event_kvm_log', 
//                   'Styles', 
//                   'kvm_metabox',
//                   'event-recurring', 
//                   'side',
//                   'low');
  }

  function event_kvm_log_metabox_callback($post)
  {
    wp_nonce_field( 'event_kvm_log_nonce', 'event_kvm_log_nonce' );

    $value = get_post_meta( $post->ID, 'event_kvm_log', true );
    echo '<textarea style="width:100%" id="event_kvm_log" name="event_kvm_log" disabled="true">' . esc_attr( $value ) . '</textarea>';
  }

  function kvm_event_id_metabox_callback($post)
  {
    wp_nonce_field( 'kvm_event_id_nonce', 'kvm_event_id_nonce' );

    $value = get_post_meta( $post->ID, 'kvm_event_id', true );
    echo '<textarea style="width:100%" id="kvm_event_id" name="kvm_event_id" disabled="true">' . esc_attr( $value ) . '</textarea>';
  }

  public function get_description() 
  {
    return 'Events Manager';
  }

  public function get_identifier() 
  {
    return 'events-manager';
  }

  public function is_feed_available() 
  {
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    return is_plugin_active( 'events-manager/events-manager.php');
  }
}

}
