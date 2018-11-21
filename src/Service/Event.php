<?php

/**
 * This library handles the creation and deletion of site "events"
 *
 * @package     Nails
 * @subpackage  module-event
 * @category    Library
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Event\Service;

use Nails\Common\Traits\ErrorHandling;
use Nails\Common\Traits\GetCountCommon;
use Nails\Components;
use Nails\Environment;
use Nails\Event\Exception\EventException;
use Nails\Factory;

class Event
{
    use ErrorHandling;
    use GetCountCommon;

    // --------------------------------------------------------------------------

    private $aEventTypes;
    private $sTable;
    private $sTableAlias;

    // --------------------------------------------------------------------------

    /**
     * Construct the Service
     */
    public function __construct()
    {
        //  Set defaults
        $this->aEventTypes = [];
        $this->sTable      = NAILS_DB_PREFIX . 'event';
        $this->sTableAlias = 'e';

        // --------------------------------------------------------------------------

        //  Look for event types defined by enabled modules
        $aModules = Components::modules();

        foreach ($aModules as $oModule) {

            $sPath = $oModule->path . $oModule->moduleName . '/config/event_types.php';

            if (file_exists($sPath)) {

                include $sPath;

                if (!empty($config['event_types'])) {
                    foreach ($config['event_types'] as $oType) {
                        $this->addType($oType);
                    }
                }
            }
        }

        //  Finally, look for app email types
        $sPath = APPPATH . 'config/event_types.php';

        if (file_exists($sPath)) {

            include $sPath;

            if (!empty($config['event_types'])) {
                foreach ($config['event_types'] as $oType) {
                    $this->addType($oType);
                }
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Adds a new event type to the stack
     *
     * @param  mixed  $mSlug        The event's slug; calling code refers to events
     *                              by this value. Alternatively pass a stdClass to set all values.
     * @param  string $sLabel       The human friendly name to give the event
     * @param  string $sDescription The human friendly description of the event's purpose
     * @param  array  $aHooks       An array of hooks to fire when an event is fired
     *
     * @return boolean
     */
    public function addType($mSlug, $sLabel = '', $sDescription = '', $aHooks = [])
    {
        if (empty($mSlug)) {
            return false;
        }

        if (is_string($mSlug)) {

            $this->aEventTypes[$mSlug]              = new \stdClass();
            $this->aEventTypes[$mSlug]->slug        = $mSlug;
            $this->aEventTypes[$mSlug]->label       = $sLabel;
            $this->aEventTypes[$mSlug]->description = $sDescription;
            $this->aEventTypes[$mSlug]->hooks       = $aHooks;

        } else {

            if (empty($mSlug->slug)) {
                return false;
            }

            $this->aEventTypes[$mSlug->slug]              = new \stdClass();
            $this->aEventTypes[$mSlug->slug]->slug        = $mSlug->slug;
            $this->aEventTypes[$mSlug->slug]->label       = $mSlug->label;
            $this->aEventTypes[$mSlug->slug]->description = $mSlug->description;
            $this->aEventTypes[$mSlug->slug]->hooks       = $mSlug->hooks;
        }

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Creates an event object
     *
     * @param  string  $sType      The type of event to create
     * @param  mixed   $mData      Any data to store alongside the event object
     * @param  integer $iCreatedBy The event creator (null == system)
     * @param  integer $iRef       A numeric reference to store alongside the event
     *                             (e.g the id of the object the event relates to)
     * @param  string  $sRecorded  A strtotime() friendly string of the date to use instead of NOW() for the created
     *                             date
     *
     * @throws EventException
     * @return mixed               Int on success false on failure
     */
    public function create($sType, $mData = null, $iCreatedBy = null, $iRef = null, $sRecorded = null)
    {
        /**
         * When logged in as an admin events should not be created. Hide admin activity on
         * production only, all other environments should generate events so they can be tested.
         */

        if (Environment::is(Environment::ENV_PROD) && wasAdmin()) {
            return true;
        }

        // --------------------------------------------------------------------------

        if (empty($sType)) {
            $this->setError('Event type not defined.');
            return false;
        }

        // --------------------------------------------------------------------------

        if (!is_string($sType)) {
            $this->setError('Event type must be a string.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Get the event type
        if (!isset($this->aEventTypes[$sType])) {
            throw new EventException('Unrecognised event type', 1);
        }

        // --------------------------------------------------------------------------

        //  Prep created by
        if (empty($iCreatedBy)) {
            $iCreatedBy = activeUser('id') ? (int) activeUser('id') : null;
        }

        // --------------------------------------------------------------------------

        //  Prep data
        $aCreateData               = [];
        $aCreateData['type']       = $sType;
        $aCreateData['created_by'] = $iCreatedBy;
        $aCreateData['url']        = uri_string();
        $aCreateData['data']       = $mData ? json_encode($mData) : null;
        $aCreateData['ref']        = (int) $iRef;
        $aCreateData['ref']        = $aCreateData['ref'] ? $aCreateData['ref'] : null;

        $oDb = Factory::service('Database');
        $oDb->set($aCreateData);

        if ($sRecorded) {
            $aCreateData['created'] = date('Y-m-d H:i:s', strtotime($sRecorded));
        } else {
            $oDb->set('created', 'NOW()', false);
        }

        //  Create the event
        $oDb->insert($this->sTable);

        // --------------------------------------------------------------------------

        if (!$oDb->affected_rows()) {

            $this->setError('Event could not be created');
            return false;

        } else {

            //  Call any hooks
            if (!empty($this->aEventTypes[$sType]->hooks)) {

                foreach ($this->aEventTypes[$sType]->hooks as $hook) {

                    if (empty($hook['path'])) {
                        continue;
                    }

                    if (!file_exists($hook['path'])) {
                        continue;
                    }

                    include_once $hook['path'];

                    if (!class_exists($hook['class'])) {
                        continue;
                    }

                    $class    = new $hook['class']();
                    $iReflect = new \ReflectionClass($class);

                    try {
                        $method = $iReflect->getMethod($hook['method']);
                    } catch (\Exception $e) {
                        continue;
                    }

                    if (!$method->isPublic() && !$method->isStatic()) {
                        continue;
                    }

                    if ($method->isStatic()) {
                        $hook['class']::$hook['method']($sType, $aCreateData);
                    } else {
                        $class->$hook['method']($sType, $aCreateData);
                    }
                }
            }

            return $oDb->insert_id();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Destroys an event object
     *
     * @param  integer $iId The event ID
     *
     * @return boolean
     */
    public function destroy($iId)
    {
        if (empty($iId)) {
            $this->setError('Event ID not defined.');
            return false;
        }

        // -------------------------------------------------------------------------

        //  Perform delete
        $oDb = Factory::service('Database');
        $oDb->where('id', $iId);
        $oDb->delete($this->sTable);

        if ($oDb->affected_rows()) {
            return true;
        } else {
            $this->setError('Event failed to delete');
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns all event objects.
     *
     * @param  integer $iPage    The page of objects to return
     * @param  integer $iPerPage The number of objects per page
     * @param  array   $aData    Any data to pass to getCountCommon
     *
     * @return object
     */
    public function getAllRawQuery($iPage = null, $iPerPage = null, $aData = [])
    {
        //  Fetch all objects from the table
        $oDb = Factory::service('Database');
        $oDb->select($this->sTableAlias . '.*');
        $oDb->select('ue.email,u.first_name,u.last_name,u.profile_img,u.gender');

        //  Apply common items; pass $aData
        $this->getCountCommonEvent($aData);

        // --------------------------------------------------------------------------

        //  Facilitate pagination
        if (!is_null($iPage)) {

            /**
             * Adjust the page variable, reduce by one so that the offset is calculated
             * correctly. Make sure we don't go into negative numbers
             */

            $iPage--;
            $iPage = $iPage < 0 ? 0 : $iPage;

            //  Work out what the offset should be
            $iPerPage = is_null($iPerPage) ? 50 : (int) $iPerPage;
            $iOffset  = $iPage * $iPerPage;

            $oDb->limit($iPerPage, $iOffset);
        }

        return $oDb->get($this->sTable . ' ' . $this->sTableAlias);
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches all emails from the archive and formats them, optionally paginated
     *
     * @param int   $iPage    The page number of the results, if null then no pagination
     * @param int   $iPerPage How many items per page of paginated results
     * @param mixed $aData    Any data to pass to getCountCommon()
     *
     * @return array
     */
    public function getAll($iPage = null, $iPerPage = null, $aData = [])
    {
        $oResults   = $this->getAllRawQuery($iPage, $iPerPage, $aData);
        $aResults   = $oResults->result();
        $numResults = count($aResults);

        for ($i = 0; $i < $numResults; $i++) {
            $this->formatObject($aResults[$i]);
        }

        return $aResults;
    }

    // --------------------------------------------------------------------------

    /**
     * This method applies the conditionals which are common across the get_*()
     * methods and the count() method.
     *
     * @param array $aData Data passed from the calling method
     *
     * @return void
     **/
    protected function getCountCommonEvent($aData = [])
    {
        if (!empty($aData['keywords'])) {
            if (empty($aData['or_like'])) {
                $aData['or_like'] = [];
            }

            $sToSlug = strtolower(str_replace(' ', '_', $aData['keywords']));

            $aData['or_like'][] = [
                'column' => $this->sTableAlias . '.type',
                'value'  => $sToSlug,
            ];
            $aData['or_like'][] = [
                'column' => 'ue.email',
                'value'  => $aData['keywords'],
            ];
        }

        //  Common joins
        $oDb = Factory::service('Database');
        $oDb->join(NAILS_DB_PREFIX . 'user u', $this->sTableAlias . '.created_by = u.id', 'LEFT');
        $oDb->join(NAILS_DB_PREFIX . 'user_email ue', 'ue.user_id = u.id AND ue.is_primary = 1', 'LEFT');

        $this->getCountCommon($aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Count the total number of events for a certain query
     *
     * @param $aData
     *
     * @return int
     */
    public function countAll($aData)
    {
        $this->getCountCommonEvent($aData);
        $oDb = Factory::service('Database');
        return $oDb->count_all_results($this->sTable . ' ' . $this->sTableAlias);
    }

    // --------------------------------------------------------------------------

    /**
     * Return an individual event
     *
     * @param  integer $iId The event's ID
     *
     * @return mixed        stdClass on success, false on failure
     */
    public function getById($iId)
    {
        $aEvents = $this->getAll([
            'where' => [
                [$this->sTableAlias . '.id', $iId],
            ],
        ]);

        if (!$aEvents) {
            return false;
        }

        return $aEvents[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns events of a particular type
     *
     * @param  string $sType The type of event to return
     *
     * @return boolean|array
     */
    public function getByType($sType)
    {
        $aEvents = $this->getAll([
            'where' => [
                [$this->sTableAlias . '.type', $sType],
            ],
        ]);

        if (!$aEvents) {
            return false;
        }

        return $aEvents[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns events created by a user
     *
     * @param  integer $iUserId The ID of the user
     *
     * @return boolean|array
     */
    public function getByUser($iUserId)
    {
        $aEvents = $this->getAll([
            'where' => [
                [$this->sTableAlias . '.created_by', $iUserId],
            ],
        ]);

        if (!$aEvents) {
            return false;
        }

        return $aEvents[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the differing types of event
     * @return array
     */
    public function getAllTypes()
    {
        return $this->aEventTypes;
    }

    // --------------------------------------------------------------------------

    /**
     * Get an individual type of event
     *
     * @param  string $sSlug The event's slug
     *
     * @return mixed        stdClass on success, false on failure
     */
    public function getTypeBySlug($sSlug)
    {
        return isset($this->aEventTypes[$sSlug]) ? $this->aEventTypes[$sSlug] : false;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the differing types of event as a flat array
     * @return array
     */
    public function getAllTypesFlat()
    {
        $aTypes = $this->getAllTypes();
        $aOut   = [];

        foreach ($aTypes as $oType) {
            $aOut[$oType->slug] = $oType->label ? $oType->label : title_case(str_replace('_', ' ', $oType->slug));
        }

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Formats an event object
     *
     * @param  \stdClass $oObj The event object to format
     *
     * @return void
     */
    protected function formatObject(&$oObj)
    {
        //  Ints
        $oObj->ref = $oObj->ref ? (int) $oObj->ref : null;

        //  Type
        $temp = $this->getTypeBySlug($oObj->type);

        if (empty($temp)) {

            $temp              = new \stdClass();
            $temp->slug        = $oObj->type;
            $temp->label       = '';
            $temp->description = '';
            $temp->hooks       = [];
        }

        $oObj->type = $temp;

        //  Data
        $oObj->data = json_decode($oObj->data);

        //  User
        $oObj->user              = new \stdClass();
        $oObj->user->id          = $oObj->created_by;
        $oObj->user->email       = $oObj->email;
        $oObj->user->first_name  = $oObj->first_name;
        $oObj->user->last_name   = $oObj->last_name;
        $oObj->user->profile_img = $oObj->profile_img;
        $oObj->user->gender      = $oObj->gender;

        unset($oObj->created_by);
        unset($oObj->email);
        unset($oObj->first_name);
        unset($oObj->last_name);
        unset($oObj->profile_img);
        unset($oObj->gender);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns protected property $table
     * @return string
     */
    public function getTableName()
    {
        return $this->sTable;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns protected property $tableAlias
     * @return string
     */
    public function getTableAlias()
    {
        return $this->sTableAlias;
    }
}
