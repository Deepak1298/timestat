<?php
define('AJAX_SCRIPT', true);
require('../../config.php');
require_once($CFG->dirroot . '/blocks/timestat/locallib.php');

require_login();
header('Content-Type: application/json');

$userid   = required_param('userid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$datefrom = required_param('datefrom', PARAM_INT);
$dateto   = required_param('dateto', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/timestat:viewreport', $context);

// Fetch logs for this user in the date range
$detaillogs = $DB->get_records_sql("
    SELECT l.timecreated, l.action, l.component, l.target, m.name as modname
    FROM {logstore_standard_log} l
    LEFT JOIN {course_modules} cm ON cm.id = l.contextinstanceid
    LEFT JOIN {modules} m ON m.id = cm.module
    WHERE l.userid = :userid
    AND l.courseid = :courseid
    AND l.timecreated >= :datefrom
    AND l.timecreated <= :dateto
    ORDER BY l.timecreated ASC
", [
    'userid'   => $userid,
    'courseid' => $courseid,
    'datefrom' => $datefrom,
    'dateto'   => $dateto,
]);
if (empty($detaillogs)) {
    echo json_encode(['summary' => 'No logs found for this user in the selected date range.']);
    exit;
}


// Build a readable log string to send to Gemini
$logtext = "";
foreach ($detaillogs as $log) {
    $time    = userdate($log->timecreated, get_string('strftimedatetime'));
    $module  = $log->modname ?? 'site';
    $action  = $log->action . ' ' . $log->target;
    $logtext .= "[$time] $module - $action\n";
}

$apikey = 'api_key';
$prompt = "The following are Moodle activity logs for a student. Please summarize what the user did,
            how engaged they were, and any patterns you notice. Format your response as clean HTML 
            using only these tags: <h5>, <p>, <ul>, <li>, <strong>, <em>. Do not use markdown, do not use backticks,
            do not include <html> or <body> tags. Just the content tags directly.\n\n$logtext";
// Check if cURL is available
if (!function_exists('curl_init')) {
    echo json_encode(['summary' => 'cURL is not enabled on this server']);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,
    "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-goog-api-key: ' . $apikey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'contents' => [[
        'parts' => [['text' => $prompt]]
    ]]
]));

$response = curl_exec($ch);
$curlerror = curl_error($ch);
curl_close($ch);

$data = json_decode($response, true);
$summary = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Could not generate summary.';
echo json_encode(['summary' => $summary]);
exit;

