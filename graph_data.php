<?php
define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();
header('Content-Type: application/json');

$courseid = required_param('courseid', PARAM_INT);
$datefrom = required_param('datefrom', PARAM_INT);
$dateto   = required_param('dateto', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/timestat:viewreport', $context);

// Fetch all logs for the course in the date range
$logs = $DB->get_records_sql("
    SELECT l.userid, l.timecreated, l.target
    FROM {logstore_standard_log} l
    WHERE l.courseid = :courseid
    AND l.timecreated >= :datefrom
    AND l.timecreated <= :dateto
    AND l.userid > 0
    AND l.userid != :guestid
    ORDER BY l.timecreated ASC
", [
    'courseid' => $courseid,
    'datefrom' => $datefrom,
    'dateto'   => $dateto,
    'guestid'  => 1,
]);

if (empty($logs)) {
    echo json_encode(['error' => 'No logs found in this date range']);
    exit;
}

// Group activities completed per user per week
$weeklydata = [];
$users = [];

foreach ($logs as $log) {
    // Get the Monday of the week for this log
    $dayofweek = date('N', $log->timecreated); // 1=Monday, 7=Sunday
    $weekstart = $log->timecreated - (($dayofweek - 1) * 86400);
    $weekstart = strtotime('midnight', $weekstart);
    $weeklabel = date('d M Y', $weekstart);

    $userid = $log->userid;
    $users[$userid] = true;

    if (!isset($weeklydata[$weeklabel])) {
        $weeklydata[$weeklabel] = [];
    }
    if (!isset($weeklydata[$weeklabel][$userid])) {
        $weeklydata[$weeklabel][$userid] = 0;
    }
    $weeklydata[$weeklabel][$userid]++;
}

// Sort by date
ksort($weeklydata);

$allweeks = [];
$weekcursor = strtotime('midnight', $datefrom - (( date('N', $datefrom) - 1) * 86400));
$weekend = strtotime('midnight', $dateto);

while ($weekcursor <= $weekend + (7 * 86400)) {
    $weeklabel = date('d M Y', $weekcursor);
    $allweeks[$weeklabel] = isset($weeklydata[$weeklabel]) ? $weeklydata[$weeklabel] : [];
    $weekcursor += 7 * 86400;
}

$totalusers = count($users);
$labels = [];
$averages = [];

foreach ($allweeks as $week => $useractivities) {
    $labels[] = $week;
    if (empty($useractivities)) {
        $averages[] = 0;
    } else {
        $totalactivities = array_sum($useractivities);
        $averages[] = round($totalactivities / $totalusers, 2);
    }
}

echo json_encode([
    'labels'   => $labels,
    'averages' => $averages,
]);
exit;
/*
JOIN {user_enrolments} ue ON ue.userid = l.userid
JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = l.courseid
JOIN {role_assignments} ra ON ra.userid = l.userid
JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
*/