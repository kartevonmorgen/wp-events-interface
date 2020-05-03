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
      add_filter( 'em_event_save', array( $this, 'em_event_saved' ) );
    }
  }

  public function em_event_saved($result=NULL, $event_id=NULL)
  {
    if ( $result == TRUE )
    {
      if ( empty( $event_id ) ) 
      {
        global $EM_Event;
        if( $EM_Event instanceof EM_Event )
        {
          $event_id = $EM_Event->event_id;
        }
      }

      $this->fire_event_saved($event_id);
    }
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
    $filters = array($event_id);
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


    $categories = get_the_terms($post->ID, 'event-categories');
    $tags = get_the_terms($post->ID, 'event-tags');

    $eiEvent = new EICalendarEvent();
    $eiEvent->set_title( $event->event_name );
    $eiEvent->set_slug( $event->event_slug );
    $eiEvent->set_description( stripslashes_deep( $post->post_content ));
    $eiEvent->set_excerpt( stripslashes_deep( $post->post_excerpt ));
    $eiEvent->set_link( $permalink );

    $eiEvent->set_event_id( $event->event_id );
    $eiEvent->set_blog_id( $event->blog_id );

		$eiEvent->set_plugin( $this->get_identifier() );

    $eiEvent->set_start_date( $event_start_datetime );
    $eiEvent->set_end_date( $event_end_datetime );
    $eiEvent->set_all_day( $event->event_all_day );
		$eiEvent->set_published_date( $event->post_date );
		$eiEvent->set_updated_date( $event->post_modified );

    $eiEvent->set_location_name( $location->location_name );
    $eiEvent->set_location_address( $location->location_address );
    $eiEvent->set_location_city( $location->location_town );
    $eiEvent->set_location_state( $location->location_state );
    $eiEvent->set_location_zip( $location->location_postcode );
    $eiEvent->set_location_country( $location->location_country );
    
    $eiEvent->set_contact_name( $event->event_owner_name );
    $eiEvent->set_contact_email( $event->event_owner_email );
    
    $eiEvent->set_event_image_url( $image_url );
    $eiEvent->set_event_cost( ($event->is_free() && !$event->event_rsvp ) ? __( 'FREE', 'events-interface' ) : '??COST??');

		$eiEvent->set_categories( 
      EICalendarEventCategory::create_categories($categories));
    $eiEvent->set_tags( EICalendarEventTag::create_tags($tags));

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
      $this->set_suppress_save_event(true);

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
      $emEvent->post_excerpt = $eiEvent->get_excerpt();
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

      if((!empty( $eiEvent->get_location_name())) && 
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
        $emTags = $this->save_event_tags($eiEvent, $result);
        if($result->has_error())
        {
          return $result;
        }
        $emEvent->tags = $emTags;
      }

      $emEvent->save();

      // Set the event_id and return the object.
      return $result;
    }
    finally
    {
      // because there where
      // multiple save actions done, we disabled 
      // the fire_event_saved(..) 
      // So we fire it afterwards
      $this->set_suppress_save_event(false);
      if( !empty($emEvent ))
      {
        $this->fire_event_saved($emEvent->event_id);
      }
    }
  }

  private function save_event_location($eiEvent, $result)
  {
    $is_new = false;
    $filter = array();
    if(!empty( $eiEvent->get_location_name()))
    {
      $filters['name'] = $eiEvent->get_location_name();
    }
    if(!empty( $eiEvent->get_location_address()))
    {
      $filters['address'] = $eiEvent->get_location_address();
    }
    if(!empty( $eiEvent->get_location_zip()))
    {
      $filters['postcode'] = $eiEvent->get_location_zip();
    }
    if(!empty( $eiEvent->get_location_city()))
    {
      $filters['town'] = $eiEvent->get_location_city();
    }
    if(!empty( $eiEvent->get_location_state()))
    {
      $filters['town'] = $eiEvent->get_location_state();
    }
    if(!empty( $eiEvent->get_location_country()))
    {
      $filters['town'] = $eiEvent->get_location_country();
    }
    $findEmLocations = EM_Locations::get($filter);

    if(!empty($findEmLocations))
    {
      $emLocation = reset($findEmLocations);
      $emLocation->location_name = $eiEvent->get_location_name();
    }
    else
    {
      $emLocation = new EM_Location();
      $emLocation->location_name = $eiEvent->get_location_name();
      $emLocation->location_address = $eiEvent->get_location_address();
      $emLocation->location_postcode = $eiEvent->get_location_zip();
      $emLocation->location_town = $eiEvent->get_location_city();
      $emLocation->location_state = $eiEvent->get_location_state();
      $emLocation->location_country = $eiEvent->get_location_country();
      $emLocation->post_status = 'publish';
      $emLocation->location_status = 1;
      $emLocation->post_content = '';
      $emLocation->owner = $eiEvent->get_owner_user_id();
      $is_new = true;
    }

    if( !$emLocation->can_manage('publish_locations', 
                                 'publish_locations',
                                 $eiEvent->get_owner_user_id()))
    {
      $result->set_error('No Permission to save an EM_Location');
      return null;
    }

    if ( $emLocation->save() === FALSE )
    {
      $result->set_error( 'SAVE LOCATION ERROR '. 
                          implode( ",", $emLocation->get_errors()) );
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

  private function save_event_tags($eiEvent, $result)
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
    
    $emTags = new EM_Tags($term_tag_ids);
    $emTags->blog_id  = $eiEvent->get_blog_id();
    $emTags->event_id = $result->get_event_id();
    if ( property_exists( $emTags, 'owner'   ) == FALSE)
    {
      $emTags->owner = $eiEvent->get_owner_user_id();
    }

    $emTags->save(); 
    return $emTags;
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