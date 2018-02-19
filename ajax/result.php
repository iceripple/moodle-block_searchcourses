<?php
require_once('../../../config.php');
require_login();

if (!defined('AJAX_SCRIPT')) {
    define('AJAX_SCRIPT', true);
}
require_once($CFG->libdir . '/datalib.php');
global $CFG;
$USER;
$query = $_GET['term'];
$my_courses_flag = 0;
$total = 0;
$courses['query'] = $query;
$course_count = 15; // Default value for course result list
if (!empty($_GET['course_count'])) {
    $course_count = $_GET['course_count'];
}
if (isset($_GET['my_courses_flag'])  &&  $_GET['my_courses_flag'] == "true") {
    $my_courses_flag = $_GET['my_courses_flag'];
    $courses['results'] = enrol_get_my_courses(array('id', 'shortname'), 'visible DESC,sortorder ASC', $course_count);
    //Once you have the results, filter the ones matching the search query
    $mycourses = array();
    foreach ($courses['results'] as $objCourse) {
        if (preg_match('/' . $query . '/i', $objCourse->fullname) != 0) {
            $mycourses[] = $objCourse;
        }
    }
    $courses['results'] = array_values($mycourses);
    echo json_encode($courses);

} else {
    $query = preg_split('/\s+/', $query);
    $courses['results'] = array_values(block_searchourses_courses_search(
        $query, 'fullname ASC', 0, $course_count, $total));
    if (empty($courses['results'])) {
        $courses = array();
        echo json_encode($courses);
    } else {
        foreach($courses['results'] as $course){
            $objCrse = new \stdClass();
            $objCrse->fullname = $course->fullname;
            $objCrse->shortname = $course->shortname;
            $objCrse->id = $course->id;
            $arrCourse[] = $objCrse;
            //$arrCourse[]['id']  = $course->id;
            //$arrCourse[]['shortname']  = $course->shortname;
            //$arrCourse[]['description']  = $course->summary;

        }
        echo json_encode($arrCourse);
    }
}


/**
 * A list of courses that match a search
 * modified copy of global function courses_search - this function does *not* search in course descriptions
 *
 * @global object
 * @global object
 * @param array $searchterms An array of search criteria
 * @param string $sort A field and direction to sort by
 * @param int $page The page number to get
 * @param int $recordsperpage The number of records per page
 * @param int $totalcount Passed in by reference.
 * @param array $requiredcapabilities Extra list of capabilities used to filter courses
 * @return object {@link $COURSE} records
 */
function block_searchourses_courses_search($searchterms, $sort, $page, $recordsperpage, &$totalcount,
        $requiredcapabilities = array()) {
            global $CFG, $DB;

            if ($DB->sql_regex_supported()) {
                $REGEXP    = $DB->sql_regex(true);
                $NOTREGEXP = $DB->sql_regex(false);
            }

            $searchcond = array();
            $params     = array();
            $i = 0;

            // Thanks Oracle for your non-ansi concat and type limits in coalesce. MDL-29912
            if ($DB->get_dbfamily() == 'oracle') {
                $concat = "(c.summary|| ' ' || c.fullname || ' ' || c.idnumber || ' ' || c.shortname)";
            } else {
                $concat = $DB->sql_concat('c.fullname', "' '", 'c.idnumber', "' '", 'c.shortname');
            }

            foreach ($searchterms as $searchterm) {
                $i++;

                $NOT = false; /// Initially we aren't going to perform NOT LIKE searches, only MSSQL and Oracle
                /// will use it to simulate the "-" operator with LIKE clause

                /// Under Oracle and MSSQL, trim the + and - operators and perform
                /// simpler LIKE (or NOT LIKE) queries
                if (!$DB->sql_regex_supported()) {
                    if (substr($searchterm, 0, 1) == '-') {
                        $NOT = true;
                    }
                    $searchterm = trim($searchterm, '+-');
                }

                // TODO: +- may not work for non latin languages

                if (substr($searchterm,0,1) == '+') {
                    $searchterm = trim($searchterm, '+-');
                    $searchterm = preg_quote($searchterm, '|');
                    $searchcond[] = "$concat $REGEXP :ss$i";
                    $params['ss'.$i] = "(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)";

                } else if ((substr($searchterm,0,1) == "-") && (core_text::strlen($searchterm) > 1)) {
                    $searchterm = trim($searchterm, '+-');
                    $searchterm = preg_quote($searchterm, '|');
                    $searchcond[] = "$concat $NOTREGEXP :ss$i";
                    $params['ss'.$i] = "(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)";

                } else {
                    $searchcond[] = $DB->sql_like($concat,":ss$i", false, true, $NOT);
                    $params['ss'.$i] = "%$searchterm%";
                }
            }

            if (empty($searchcond)) {
                $searchcond = array('1 = 1');
            }

            $searchcond = implode(" AND ", $searchcond);

            $courses = array();
            $c = 0; // counts how many visible courses we've seen

            // Tiki pagination
            $limitfrom = $page * $recordsperpage;
            $limitto   = $limitfrom + $recordsperpage;

            $ccselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
            $ccjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
            $params['contextlevel'] = CONTEXT_COURSE;

            $sql = "SELECT c.* $ccselect
              FROM {course} c
           $ccjoin
             WHERE $searchcond AND c.id <> ".SITEID."
          ORDER BY $sort";

           $rs = $DB->get_recordset_sql($sql, $params);
           foreach($rs as $course) {
               // Preload contexts only for hidden courses or courses we need to return.
               context_helper::preload_from_record($course);
               $coursecontext = context_course::instance($course->id);
               if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
                   continue;
               }
               if (!empty($requiredcapabilities)) {
                   if (!has_all_capabilities($requiredcapabilities, $coursecontext)) {
                       continue;
                   }
               }
               // Don't exit this loop till the end
               // we need to count all the visible courses
               // to update $totalcount
               if ($c >= $limitfrom && $c < $limitto) {
                   $courses[$course->id] = $course;
               }
               $c++;
           }
           $rs->close();

           // our caller expects 2 bits of data - our return
           // array, and an updated $totalcount
           $totalcount = $c;
           return $courses;
}
