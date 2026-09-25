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

namespace local_nucleushub\local;

use moodle_page;

/**
 * Tests for the hub's part of the Nucleus bar away from course pages.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(statusbar::class)]
final class statusbar_test extends \advanced_testcase {
    /**
     * Three shared courses, one with unpublished changes, and two active
     * spokes (plus a removed one, which doesn't count).
     */
    private function make_hub(): void {
        global $DB;

        $now = time();
        foreach ([0, 2, 0] as $i => $pending) {
            $DB->insert_record('local_nucleushub_draft', (object) [
                'familyid' => 100 + $i,
                'hubcourseid' => 200 + $i,
                'pendingchangecount' => $pending,
                'timecreated' => $now,
            ]);
        }
        foreach (['active', 'active', 'removed'] as $i => $status) {
            $DB->insert_record('local_nucleushub_spokes', (object) [
                'name' => "Spoke $i",
                'wwwroot' => "https://spoke$i.example",
                'token' => '',
                'status' => $status,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
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
     * Off course pages the bar sums up the hub, and links to the course families.
     */
    public function test_sums_up_the_hub(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_hub();

        $widget = statusbar::widget(self::dashboard());
        $this->assertSame([
            ['text' => 'Shared courses: 3', 'attention' => false],
            ['text' => 'Courses with unpublished changes: 1', 'attention' => true],
        ], $widget['segments']);
        $this->assertSame('/local/nucleushub/families.php', $widget['action']['url']->out_as_local_url(false));
        $this->assertContains('Spokes: 2. Each one is told when you publish.', $widget['rows'][0]['lines']);
    }

    /**
     * With nothing waiting to be published, it says the hub is up to date.
     */
    public function test_up_to_date(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_hub();
        $DB->set_field('local_nucleushub_draft', 'pendingchangecount', 0);

        $widget = statusbar::site_widget();
        $this->assertSame(['text' => 'Up to date', 'attention' => false], $widget['segments'][1]);
    }

    /**
     * Someone who can't publish gets nothing, anywhere.
     */
    public function test_nothing_for_others(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertNull(statusbar::site_widget());
        $this->assertNull(statusbar::widget(self::dashboard()));
    }
}
