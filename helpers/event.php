<?php

use Nails\Factory;

/**
 * This file provides event related helper functions
 *
 * @package     Nails
 * @subpackage  module-event
 * @category    Helper
 * @author      Nails Dev Team
 * @link
 */

if (!function_exists('create_event')) {

    /**
     * Creates an event object
     *
     * @param  string  $sType      The type of event to create
     * @param  mixed   $aData      Any data to store alongside the event object
     * @param  integer $iCreatedBy The event creator (null == system)
     * @param  integer $iRef       A numeric reference to store alongside the event (e.g the ID of the object the event relates to)
     * @param  string  $sRecorded  A strtotime() friendly string of the date to use instead of NOW() for the created date
     *
     * @return mixed              Int on success false on failure
     */
    function create_event($sType, $aData = null, $iCreatedBy = null, $iRef = null, $sRecorded = null)
    {
        $oEvent = Factory::service('Event', 'nails/module-event');
        return $oEvent->create($sType, $aData, $iCreatedBy, $iRef, $sRecorded);
    }
}
