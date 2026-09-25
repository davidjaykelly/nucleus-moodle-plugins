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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_nucleusspoke\local;

use moodle_page;

/**
 * Tests for the spoke's part of the Nucleus bar away from course pages.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(statusbar::class)]
final class statusbar_test extends \advanced_testcase {
    /**
     * Make this site a spoke with three courses from the hub.
     */
    private function make_spoke(): void {
        global $DB;

        set_config('hubwwwroot', 'https://hub.example', 'local_nucleusspoke');
        $now = time();
        foreach ([1, 2, 3] as $i) {
            $DB->insert_record('local_nucleusspoke_instance', (object) [
                'familyid' => $i,
                'versionid' => 10 + $i,
                'localcourseid' => 20 + $i,
                'state' => 'active',
                'timepulled' => $now,
                'pulledbyid' => 2,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Record a notice of a new version.
     *
     * @param string $state
     */
    private function notice(string $state): void {
        global $DB;
        static $n = 0;
        $n++;
        $DB->insert_record('local_nucleusspoke_notification', (object) [
            'familyid' => $n,
            'versionid' => 100 + $n,
            'state' => $state,
            'timereceived' => time(),
        ]);
    }

    /**
     * A page that isn't a course.
     *
     * @return moodle_page
     */
    private static function dashboard(): moodle_page {
        $page = new moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagetype('my-index');
        return $page;
    }

    /**
     * With updates waiting, it counts them and links to Course versions.
     */
    public function test_counts_updates_waiting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_spoke();
        $this->notice('pending');
        $this->notice('pending');
        $this->notice('dismissed');

        $widget = statusbar::widget(self::dashboard());
        $this->assertSame([
            ['text' => 'Courses from the hub: 3', 'attention' => false],
            ['text' => 'Updates: 2', 'attention' => true],
        ], $widget['segments']);
        $this->assertSame('/local/nucleusspoke/versions.php', $widget['action']['url']->out_as_local_url(false));
        $this->assertContains('Updates waiting: 2. See Course versions.', $widget['rows'][0]['lines']);
    }

    /**
     * With nothing waiting, it says so and links to the catalogue.
     */
    public function test_nothing_waiting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_spoke();

        $widget = statusbar::site_widget();
        $this->assertSame(['text' => 'No updates waiting', 'attention' => false], $widget['segments'][1]);
        $this->assertSame('/local/nucleusspoke/catalog.php', $widget['action']['url']->out_as_local_url(false));
    }

    /**
     * Someone who can't pull gets nothing, and neither does a hub.
     */
    public function test_nothing_for_others(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertNull(statusbar::site_widget(), 'not a spoke');

        $this->make_spoke();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull(statusbar::site_widget());
    }
}
