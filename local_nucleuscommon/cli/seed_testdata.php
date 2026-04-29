<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Idempotent: seed a tenant with a curated, demo-friendly set of
 * users + (for hubs only) categories, courses, and per-course
 * activities. Designed for live demos and dev iteration so an
 * operator doesn't have to hand-create content every time a
 * fresh tenant comes up.
 *
 *   php local/nucleuscommon/cli/seed_testdata.php --role=hub
 *   php local/nucleuscommon/cli/seed_testdata.php --role=spoke
 *
 * Both roles seed the same 5 named users (alice / bob / carol /
 * dan / ellie) so Mode B identity demos have stable identities.
 * Hubs additionally get the curated catalog (3 categories,
 * ~10 courses, 1 page + 1 forum per course, instructor / student
 * enrolments). Spokes intentionally start without any local
 * courses — the demo flow is "spoke pulls courses from hub".
 *
 * Output: stderr for diagnostic lines, stdout final line for a
 * single-line JSON summary the control plane parses:
 *
 *   {"role":"hub","categories":3,"courses":10,"users":5,"activities":20}
 *
 * Re-runnable. Existing rows are upserted by short-name / username,
 * never duplicated.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

list($options, $unrecognised) = cli_get_params(
    [
        'role'  => 'hub',
        'help'  => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help'])) {
    echo "Usage: php seed_testdata.php --role=hub|spoke\n";
    exit(0);
}

$role = $options['role'];
if (!in_array($role, ['hub', 'spoke'], true)) {
    cli_error("--role must be 'hub' or 'spoke'", 2);
}

global $DB;

\core\session\manager::set_user(get_admin());

// Default password for demo users. Plain because this is dev /
// demo content; nothing here goes near production data.
const DEMO_PASSWORD = 'Demo!Pa55';

// ── Curated demo users (both roles) ────────────────────────────

$users = [
    ['username' => 'alice', 'firstname' => 'Alice',  'lastname' => 'Adams',   'email' => 'alice@nucleus.demo',  'role' => 'editingteacher'],
    ['username' => 'bob',   'firstname' => 'Bob',    'lastname' => 'Brown',   'email' => 'bob@nucleus.demo',    'role' => 'editingteacher'],
    ['username' => 'carol', 'firstname' => 'Carol',  'lastname' => 'Carter',  'email' => 'carol@nucleus.demo',  'role' => 'student'],
    ['username' => 'dan',   'firstname' => 'Dan',    'lastname' => 'Davies',  'email' => 'dan@nucleus.demo',    'role' => 'student'],
    ['username' => 'ellie', 'firstname' => 'Ellie',  'lastname' => 'Edwards', 'email' => 'ellie@nucleus.demo',  'role' => 'student'],
];

$userids = [];
foreach ($users as $u) {
    $existing = $DB->get_record('user', ['username' => $u['username']]);
    if ($existing) {
        $userids[$u['username']] = (int)$existing->id;
        fwrite(STDERR, "user: existing {$u['username']} id={$existing->id}\n");
        continue;
    }
    $newuser = (object) [
        'username'    => $u['username'],
        'firstname'   => $u['firstname'],
        'lastname'    => $u['lastname'],
        'email'       => $u['email'],
        'auth'        => 'manual',
        'confirmed'   => 1,
        'mnethostid'  => $CFG->mnet_localhost_id,
        'lang'        => 'en',
        'timezone'    => '99',
    ];
    $newuser->password = hash_internal_user_password(DEMO_PASSWORD);
    $userids[$u['username']] = (int)user_create_user($newuser, false, false);
    fwrite(STDERR, "user: created {$u['username']} id={$userids[$u['username']]}\n");
}

$summary = [
    'role'       => $role,
    'users'      => count($userids),
    'categories' => 0,
    'courses'    => 0,
    'activities' => 0,
];

if ($role === 'spoke') {
    fwrite(STDOUT, json_encode($summary));
    exit(0);
}

// ── Hub-only: categories, courses, activities, enrolments ──────

$categories = [
    'compliance'  => 'Compliance & Safeguarding',
    'foundations' => 'Foundations',
    'pathways'    => 'Customer-facing Pathways',
];

$catids = [];
foreach ($categories as $key => $name) {
    $existing = $DB->get_record('course_categories', ['idnumber' => "demo-{$key}"]);
    if ($existing) {
        $catids[$key] = (int)$existing->id;
        fwrite(STDERR, "category: existing {$key} id={$existing->id}\n");
        continue;
    }
    $cat = \core_course_category::create((object) [
        'name'        => $name,
        'idnumber'    => "demo-{$key}",
        'description' => "Demo category seeded by seed_testdata.php.",
    ]);
    $catids[$key] = (int)$cat->id;
    fwrite(STDERR, "category: created {$key} id={$cat->id}\n");
}
$summary['categories'] = count($catids);

$courses = [
    ['shortname' => 'safeguarding-101',     'fullname' => 'Safeguarding 101',                'category' => 'compliance',  'summary' => 'Foundational safeguarding awareness and reporting.'],
    ['shortname' => 'gdpr-refresher',       'fullname' => 'Data Protection (GDPR Refresher)','category' => 'compliance',  'summary' => 'Annual refresher on data protection responsibilities.'],
    ['shortname' => 'health-and-safety',    'fullname' => 'Health & Safety Essentials',      'category' => 'compliance',  'summary' => 'Workplace health & safety basics and incident reporting.'],
    ['shortname' => 'edi-foundations',      'fullname' => 'Equality, Diversity & Inclusion', 'category' => 'compliance',  'summary' => 'Practical EDI foundations for everyday work.'],
    ['shortname' => 'digital-skills',       'fullname' => 'Digital Skills Foundations',      'category' => 'foundations', 'summary' => 'Core digital literacy across browsers, files, and search.'],
    ['shortname' => 'communication',        'fullname' => 'Effective Communication',         'category' => 'foundations', 'summary' => 'Clear writing and confident verbal communication.'],
    ['shortname' => 'time-management',      'fullname' => 'Time Management',                 'category' => 'foundations', 'summary' => 'Prioritisation, focus, and personal workflow.'],
    ['shortname' => 'new-starter',          'fullname' => 'New Starter Onboarding',          'category' => 'pathways',    'summary' => 'First-30-days induction pathway.'],
    ['shortname' => 'first-line-manager',   'fullname' => 'First-Line Manager Pathway',      'category' => 'pathways',    'summary' => 'Stepping into a first management role.'],
    ['shortname' => 'customer-success',     'fullname' => 'Customer Success Pathway',        'category' => 'pathways',    'summary' => 'Working with customers across the lifecycle.'],
];

$courseids = [];
foreach ($courses as $c) {
    $existing = $DB->get_record('course', ['shortname' => $c['shortname']]);
    if ($existing) {
        $courseids[$c['shortname']] = (int)$existing->id;
        fwrite(STDERR, "course: existing {$c['shortname']} id={$existing->id}\n");
        continue;
    }
    $course = create_course((object) [
        'shortname'     => $c['shortname'],
        'fullname'      => $c['fullname'],
        'category'      => $catids[$c['category']],
        'summary'       => $c['summary'],
        'summaryformat' => FORMAT_HTML,
        'format'        => 'topics',
        'numsections'   => 4,
    ]);
    $courseids[$c['shortname']] = (int)$course->id;
    fwrite(STDERR, "course: created {$c['shortname']} id={$course->id}\n");
}
$summary['courses'] = count($courseids);

// Page + forum per course, idempotent on idnumber.
$activities = 0;
foreach ($courseids as $shortname => $courseid) {
    $activities += seed_activities_for_course($courseid, $shortname);
}
$summary['activities'] = $activities;

// Enrolments — instructors in 3 courses each (slice of catalog),
// students in all courses.
$instructorslice = ['alice' => array_slice(array_keys($courseids), 0, 5),
                    'bob'   => array_slice(array_keys($courseids), 5, 10)];
foreach ($instructorslice as $username => $shortnames) {
    foreach ($shortnames as $shortname) {
        ensure_enrol($courseids[$shortname], $userids[$username], 'editingteacher');
    }
}
foreach (['carol', 'dan', 'ellie'] as $student) {
    foreach ($courseids as $courseid) {
        ensure_enrol($courseid, $userids[$student], 'student');
    }
}

fwrite(STDOUT, json_encode($summary));
exit(0);

// ── helpers ────────────────────────────────────────────────────

/**
 * Create a Page + Forum activity in a course, both keyed by
 * idnumber so re-runs no-op.
 *
 * @return int Number of activities created (0 or 2).
 */
function seed_activities_for_course(int $courseid, string $shortname): int {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/modlib.php');

    $created = 0;

    // Page.
    $pagemod = $DB->get_record('modules', ['name' => 'page'], '*', MUST_EXIST);
    $pageidn = "demo-{$shortname}-page";
    if (!$DB->record_exists('course_modules', ['idnumber' => $pageidn])) {
        $pagedata = (object) [
            'course'        => $courseid,
            'name'          => 'About this course',
            'intro'         => '',
            'introformat'   => FORMAT_HTML,
            'content'       => "<p>Welcome to this course. This is demo content seeded by Nucleus.</p>",
            'contentformat' => FORMAT_HTML,
            'display'       => 5,
            'modulename'    => 'page',
            'module'        => $pagemod->id,
            'section'       => 1,
            'visible'       => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber'    => $pageidn,
        ];
        try {
            add_moduleinfo($pagedata, get_course($courseid));
            $created++;
            fwrite(STDERR, "  activity: page created in course {$courseid}\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "  activity: page failed in course {$courseid}: {$e->getMessage()}\n");
        }
    }

    // Forum.
    $forummod = $DB->get_record('modules', ['name' => 'forum'], '*', MUST_EXIST);
    $forumidn = "demo-{$shortname}-forum";
    if (!$DB->record_exists('course_modules', ['idnumber' => $forumidn])) {
        $forumdata = (object) [
            'course'      => $courseid,
            'name'        => 'Welcome & Introductions',
            'intro'       => '<p>Say hello and tell the cohort why you\'re taking this course.</p>',
            'introformat' => FORMAT_HTML,
            'type'        => 'general',
            'modulename'  => 'forum',
            'module'      => $forummod->id,
            'section'     => 1,
            'visible'     => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber'  => $forumidn,
            'assessed'    => 0,
            'scale'       => 0,
        ];
        try {
            add_moduleinfo($forumdata, get_course($courseid));
            $created++;
            fwrite(STDERR, "  activity: forum created in course {$courseid}\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "  activity: forum failed in course {$courseid}: {$e->getMessage()}\n");
        }
    }

    return $created;
}

/**
 * Idempotently enrol a user in a course with the given role using
 * the manual enrol method. No-op if already enrolled with that role.
 */
function ensure_enrol(int $courseid, int $userid, string $rolename): void {
    global $DB;

    $manual = enrol_get_plugin('manual');
    if (!$manual) return;
    $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
    if (!$instance) {
        $course = get_course($courseid);
        $manual->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
    }
    $role = $DB->get_record('role', ['shortname' => $rolename], '*', MUST_EXIST);
    $manual->enrol_user($instance, $userid, $role->id);
}
