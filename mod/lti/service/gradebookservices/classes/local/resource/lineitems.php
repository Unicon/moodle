<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains a class definition for the LineItem container resource
 *
 * @package    ltiservice_gradebookservices
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @author     Dirk Singels
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace ltiservice_gradebookservices\local\resource;

use ltiservice_gradebookservices\local\service\gradebookservices;

defined('MOODLE_INTERNAL') || die();

/**
 * A resource implementing LineItem container.
 *
 * @package    ltiservice_gradebookservices
 * @since      Moodle 3.0
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lineitems extends \mod_lti\local\ltiservice\resource_base {

    /**
     * Class constructor.
     *
     * @param ltiservice_gradebookservices\local\service\gradebookservices $service Service instance
     */
    public function __construct($service) {

        parent::__construct($service);
        $this->id = 'LineItem.collection';
        $this->template = '/{context_id}/lineitems';
        $this->variables[] = 'LineItems.url';
        $this->formats[] = 'application/vnd.ims.lis.v2.lineitemcontainer+json';
        $this->formats[] = 'application/vnd.ims.lis.v2.lineitem+json';
        $this->methods[] = 'GET';
        $this->methods[] = 'POST';

    }

    /**
     * Execute the request for this resource.
     *
     * @param mod_lti\local\ltiservice\response $response  Response object for this request.
     */
    public function execute($response) {

        $params = $this->parse_template();
        $resourceid = optional_param('resourceid', null, PARAM_TEXT);
        $resourcelinkid = optional_param('resourcelinkid', null, PARAM_TEXT);
        $limit = optional_param('limit', 0, PARAM_INT);
        $page = optional_param('page', 0, PARAM_INT);

        $contextid = $params['context_id'];
        $isget = $response->get_request_method() === 'GET';
        if ($isget) {
            $contenttype = $response->get_accept();
        } else {
            $contenttype = $response->get_content_type();
        }
        $container = empty($contenttype) || ($contenttype === $this->formats[0]);

        try {
            if (!$this->check_tool_proxy(null, $response->get_request_data())) {
                throw new \Exception(null, 401);
            }
            if (empty($contextid) || !($container ^ ($response->get_request_method() === 'POST')) ||
                (!empty($contenttype) && !in_array($contenttype, $this->formats))) {
                throw new \Exception(null, 400);
            }

            switch ($response->get_request_method()) {
                case 'GET':
                    $items = $this->get_service()->get_lineitems($contextid);
                    $json = $this->get_request_json($contextid, $items);
                    $response->set_content_type($this->formats[0]);
                    break;
                case 'POST':
                    $json = $this->post_request_json($response->get_request_data(), $contextid);
                    $response->set_code(201);
                    $response->set_content_type($this->formats[1]);
                    break;
                default:  // Should not be possible.
                    throw new \Exception(null, 405);
            }
            $response->set_body($json);

        } catch (\Exception $e) {
            $response->set_code($e->getCode());
        }

    }

    /**
     * Generate the JSON for a GET request.
     *
     * @param string $contextid  Course ID
     * @param array  $items      Array of lineitems
     *
     * return string
     */
    private function get_request_json($contextid, $items) {

        $json = <<< EOD
{
  "@context" : "http://purl.imsglobal.org/ctx/lis/v2/outcomes/LineItemContainer",
  "@type" : "Page",
  "@id" : "{$this->get_endpoint()}",
  "pageOf" : {
    "@type" : "LineItemContainer",
    "membershipSubject" : {
      "contextId" : "{$contextid}",
      "lineItem" : [

EOD;
        $endpoint = parent::get_endpoint();
        $sep = '        ';
        foreach ($items as $item) {
            $json .= $sep . gradebookservices::item_to_json($item, $endpoint, true);
            $sep = ",\n        ";
        }
        $json .= <<< EOD

      ]
    }
  }
}
EOD;

        return $json;

    }

    /**
     * Generate the JSON for a POST request.
     *
     * @param string $body       POST body
     * @param string $contextid  Course ID
     *
     * return string
     */
    private function post_request_json($body, $contextid) {
        global $CFG, $DB;

        $json = json_decode($body);
        if (empty($json) || !isset($json->{"@type"}) || ($json->{"@type"} != 'LineItem')) {
            throw new \Exception(null, 400);
        }

        require_once($CFG->libdir.'/gradelib.php');
        $label = (isset($json->label)) ? $json->label : 'Item ' . time();
        $resourceid = (isset($json->resourceId)) ? $json->resourceId : '';
        $resourcelinkid = (isset($json->resourceLinkId)) ? $json->resourceLinkId : null;
        if ($resourcelinkid != null) {
            if (!gradebookservices::check_lti_id($resourcelinkid, $contextid, $this->get_service()->get_tool_proxy()->id)) {
                throw new \Exception(null, 404);
            }
        }
        $lineitemtoolproviderid = (isset($json->lineItemToolProviderId)) ? $json->lineItemToolProviderId : '';
        if (isset($json->assignedActivity) && isset($json->assignedActivity->activityId)) {
            $activity = $json->assignedActivity->activityId;
        } else {
            $activity = '';
        }
        $max = 1;
        if (isset($json->lineItemScoreMaximum)) {
            $max = $json->lineItemScoreMaximum;
        }

        try {
            $gradebookservicesid = $DB->insert_record('ltiservice_gradebookservices', array(
                'toolproxyid' => $this->get_service()->get_tool_proxy()->id,
                    'resourcelinkid' => $resourcelinkid,
                    'lineitemtoolproviderid' => $lineitemtoolproviderid
            ));
        } catch (\Exception $e) {
            throw new \Exception(null, 500);
        }

        $params = array();
        $params['itemname'] = $label;
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $max;
        $params['grademin']  = 0;
        $item = new \grade_item(array('id' => 0, 'courseid' => $contextid));
        \grade_item::set_properties($item, $params);
        $item->itemtype = 'mod';
        $item->itemmodule = 'lti';
        $item->itemnumber = $gradebookservicesid;
        $item->idnumber = $resourceid;
        if (isset($json->resourceLinkId) && is_numeric($json->resourceLinkId)) {
            $item->iteminstance = $json->resourceLinkId;
        }
        $id = $item->insert('mod/ltiservice_gradebookservices');
        $json->{"@id"} = parent::get_endpoint() . "/{$id}";
        $json->scores = parent::get_endpoint() . "/{$id}/scores";
        if ($contextid) {
            $lineitemof = new \stdClass();
            $lineitemof->contextId = $contextid;
            $json->lineItemOf = $lineitemof;
        }

        return json_encode($json);

    }

    /**
     * Parse a value for custom parameter substitution variables.
     *
     * @param string $value String to be parsed
     *
     * @return string
     */
    public function parse_value($value) {
        global $COURSE;

        $this->params['context_id'] = $COURSE->id;

        $value = str_replace('$LineItems.url', parent::get_endpoint(), $value);

        return $value;

    }

}
