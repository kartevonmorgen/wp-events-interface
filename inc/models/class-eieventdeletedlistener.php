<?php

/**
  * EIEventDeletedListenerIF
  * This Listener can be registered by calling
  * $eiInterface = EIInterface::get_instance()
  * eiInterface->add_event_deleted_listener( .. )
  * 
  * @author     Sjoerd Takken
  * @copyright  No Copyright.
  * @license    GNU/GPLv2, see https://www.gnu.org/licenses/gpl-2.0.html
  */
interface EIEventDeletedListenerIF 
{
  /*
   * Fired wenn an Event in the native supported event calendar 
   * has been deleted.
   *
   * @param EICalendarEvent eiEvent
   */
	public function event_deleted( $eiEvent ); 
}
