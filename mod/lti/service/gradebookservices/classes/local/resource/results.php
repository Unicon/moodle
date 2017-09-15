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
 * This file contains a class definition for the LISResults container resource
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
 * A resource implementing LISResults container.
 *
 * @package    ltiservice_gradebookservices
 * @since      Moodle 3.0
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class results extends \mod_lti\local\ltiservice\resource_base {

    /**
     * Class constructor.
     *
     * @param ltiservice_gradebookservices\local\service\gradebookservices $service Service instance
     */
    public function __construct($service) {

        parent::__construct($service);
        $this->id = 'Result.collection';
        $this->template = '/{context_id}/lineitems/{item_id}/results';
        $this->variables[] = 'Results.url';
        $this->formats[] = 'application/vnd.ims.lis.v1.resultcontainer+json';
        $this->methods[] = 'GET';
    }

    /**
     * Execute the request for this resource.
     *
     * @param mod_lti\local\ltiservice\response $response  Response object for this request.
     */
    public function execute($response) {
        global $CFG;
        $params = $this->parse_template();
        $contextid = $params['context_id'];
        $itemid = $params['item_id'];

        // GET is disabled by the moment, but we have the code ready
        // for a future implementation.

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
            if (!empty($contenttype) && !in_array($contenttype, $this->formats)) {
                throw new \Exception(null, 400);
            }
            if (($item = $this->get_service()->get_lineitem($contextid, $itemid, true)) === false) {
                throw new \Exception(null, 404);
            }
            require_once($CFG->libdir.'/gradelib.php');
            switch ($response->get_request_method()) {
                case 'GET':
                    $json = $this->get_request_json($item->id);
                    $response->set_content_type($this->formats[0]);
                    $response->set_body($json);
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
     * @param int $itemid       Grade item instance ID
     *
     * return string
     */
    private function get_request_json($itemid) {

        $grades = \grade_grade::fetch_all(array('itemid' => $itemid));
        if (!$grades) {
            throw new \Exception(null, 404);
        } else {
            // TODO modify this with paging code.
            // At this moment, these numbers are just fillers.
            $nextpage = 1;
            $limit = 5;
            $json = <<< EOD
{
  "results" : [
EOD;
            $lineitem = new lineitem($this->get_service());
            $endpoint = $lineitem->get_endpoint();
            $sep = "\n        ";
            foreach ($grades as $grade) {
                if (!empty($grade->timemodified)) {
                    $json .= $sep . gradebookservices::result_to_json($grade, $endpoint);
                    $sep = ",\n        ";
                }
            }
            $json .= <<< EOD
  ]
}
EOD;
        }
        return $json;
    }


    /**
     * Parse a value for custom parameter substitution variables.
     *
     * @param string $value String to be parsed
     *
     * @return string
     */
    public function parse_value($value) {
        global $COURSE, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = grade_get_grades($COURSE->id, 'mod', 'lti', $id);
        if ($item) {
            $this->params['context_id'] = $COURSE->id;
            $id = optional_param('id', 0, PARAM_INT); // Course Module ID.
            if (!empty($id)) {
                $cm = get_coursemodule_from_id('lti', $id, 0, false);
                if ($cm) {
                    $id = $cm->instance;
                }
            }
            $this->params['item_id'] = $item->items[0]->id;

            $value = str_replace('$Results.url', parent::get_endpoint(), $value);

            return $value;
        } else {
            return '';
        }

    }

}
