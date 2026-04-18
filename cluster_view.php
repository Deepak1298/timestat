<?php
require('../../config.php');
require_once($CFG->dirroot . '/blocks/timestat/locallib.php');

require_login();

$type     = required_param('type', PARAM_ALPHA);
$courseid = required_param('courseid', PARAM_INT);
$datefrom = required_param('datefrom', PARAM_INT);
$dateto   = required_param('dateto', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/timestat:viewreport', $context);

$PAGE->set_url('/blocks/timestat/cluster_view.php', [
    'type'     => $type,
    'courseid' => $courseid,
    'datefrom' => $datefrom,
    'dateto'   => $dateto,
]);
$PAGE->set_pagelayout('report');
$PAGE->set_title($course->shortname . ': ' . $type . ' Students');
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add('Timestat', new moodle_url('/blocks/timestat/index.php', ['id' => $courseid]));
$PAGE->navbar->add($type . ' Students');

echo $OUTPUT->header();

// Color per type
$colors = [
    'Regular'    => '#28a745',
    'Occasional' => '#ffc107',
    'Inactive'   => '#dc3545',
];
$descriptions = [
    'Regular'    => 'These students are highly active and consistent in their course engagement.',
    'Occasional' => 'These students engage moderately with the course.',
    'Inactive'   => 'These students have very low engagement with the course.',
];

$color = $colors[$type] ?? '#6c757d';
$description = $descriptions[$type] ?? '';

echo '
<div style="padding:20px;">

    <!-- Heading block -->
    <div style="background:' . $color . '; color:white; padding:20px; 
        border-radius:8px; margin-bottom:30px;">
        <h2 style="margin:0;">' . $type . ' Students</h2>
        <p style="margin:5px 0 0 0;">' . $description . '</p>
    </div>

    <!-- Graph section -->
    <div style="margin-bottom:30px;">
        <h4>Weekly Activity Graph</h4>
        <div style="width:100%; height:400px;">
            <canvas id="cluster-graph"></canvas>
        </div>
    </div>

    <!-- Student list -->
    <h4>Students in this category</h4>
    <div id="cluster-student-list">
        <em>Loading students...</em>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
var clusterChart = null;
var summarycolor = "' . $color . '";

fetch("' . $CFG->wwwroot . '/blocks/timestat/kmeans.php?courseid=' . $courseid . '&datefrom=' . $datefrom . '&dateto=' . $dateto . '")
.then(function(res) { return res.json(); })
.then(function(data) {
    var clusters = data.clusters;
    var type = "' . $type . '";
    var studentlist = document.getElementById("cluster-student-list");
    studentlist.innerHTML = "";

    var filtered = [];
    for (var userid in clusters) {
        if (clusters[userid].cluster === type) {
            filtered.push(clusters[userid]);
        }
    }

    if (filtered.length === 0) {
        studentlist.innerHTML = "<p>No students in this category.</p>";
        return;
    }

    filtered.forEach(function(student) {
        var div = document.createElement("div");
        div.id = "student-block-" + student.userid;
        div.style = "padding:20px; border-radius:8px; margin-bottom:20px; background:" + summarycolor + "22; border-left:5px solid " + summarycolor + ";";
        div.innerHTML =
            "<div style=\'display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;\'>" +
            "<div>" +
            "<strong style=\'font-size:16px;\'>" + student.name + "</strong><br>" +
            "<small style=\'color:#666\'>" +
            "Active days: " + student.stats.active_days + " | " +
            "Active weeks: " + student.stats.active_weeks + " | " +
            "Total actions: " + student.stats.total_actions +
            "</small>" +
            "</div>" +
            "<button type=\'button\' class=\'btn btn-sm timestat-summarize-btn\' " +
            "style=\'background:" + summarycolor + "; color:white;\' " +
            "data-userid=\'" + student.userid + "\' " +
            "data-name=\'" + student.name + "\' " +
            "data-courseid=\'' . $courseid . '\' " +
            "data-datefrom=\'' . $datefrom . '\' " +
            "data-dateto=\'' . $dateto . '\'>" +
            "Summarize Activity" +
            "</button>" +
            "</div>" +
            "<div id=\'summary-" + student.userid + "\' style=\'display:none; margin-top:10px; padding:15px; background:white; border-radius:8px;\'>" +
            "<em>Generating summary...</em>" +
            "</div>";
        studentlist.appendChild(div);
    });

    // Attach summarize button events
    document.querySelectorAll(".timestat-summarize-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var userid   = this.dataset.userid;
            var courseid = this.dataset.courseid;
            var datefrom = this.dataset.datefrom;
            var dateto   = this.dataset.dateto;
            var summarybox = document.getElementById("summary-" + userid);

            summarybox.style.display = "block";
            summarybox.innerHTML = "<em>Generating summary...</em>";

            fetch("' . $CFG->wwwroot . '/blocks/timestat/summarize.php?userid=" + userid +
                "&courseid=" + courseid +
                "&datefrom=" + datefrom +
                "&dateto=" + dateto)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                summarybox.innerHTML = data.summary;
            })
            .catch(function(error) {
                summarybox.innerHTML = "<div class=\'alert alert-danger\'>Failed: " + error.message + "</div>";
            });
        });
    });

    buildGraph(filtered);
})
.catch(function(error) {
    document.getElementById("cluster-student-list").innerHTML =
        "<div class=\'alert alert-danger\'>Failed to load: " + error.message + "</div>";
});

function buildGraph(students) {
    fetch("' . $CFG->wwwroot . '/blocks/timestat/graph_data.php?courseid=' . $courseid . '&datefrom=' . $datefrom . '&dateto=' . $dateto . '")
    .then(function(res) { return res.json(); })
    .then(function(data) {
        var ctx = document.getElementById("cluster-graph").getContext("2d");

        if (clusterChart) {
            clusterChart.destroy();
        }

        clusterChart = new Chart(ctx, {
            type: "line",
            data: {
                labels: data.labels,
                datasets: [{
                    label: "Average Weekly Activity (' . $type . ' Students)",
                    data: data.averages,
                    borderColor: "' . $color . '",
                    backgroundColor: "' . $color . '33",
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 5,
                    pointBackgroundColor: "' . $color . '"
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: "top" },
                    title: {
                        display: true,
                        text: "' . $type . ' Student Activity Per Week"
                    }
                },
                scales: {
                    x: {
                        title: { display: true, text: "Week" },
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false,
                            font: { size: 10 }
                        }
                    },
                    y: {
                        title: { display: true, text: "Average Activities" },
                        beginAtZero: true
                    }
                }
            }
        });
    });
}
</script>
';

echo $OUTPUT->footer();