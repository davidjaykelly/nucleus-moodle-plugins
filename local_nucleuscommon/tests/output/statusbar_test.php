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

namespace local_nucleuscommon\output;

use moodle_page;

/**
 * Tests for where the Nucleus bar appears.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(statusbar::class)]
final class statusbar_test extends \advanced_testcase {
    /**
     * A page of a given layout and type, in the system context.
     *
     * @param string $layout
     * @param string $pagetype
     * @return moodle_page
     */
    private static function page(string $layout, string $pagetype): moodle_page {
        $page = new moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagelayout($layout);
        $page->set_pagetype($pagetype);
        return $page;
    }

    /**
     * People who run the Nucleus side of the site see the bar on every
     * normal page, not only course pages.
     */
    public function test_shows_on_every_page_for_operators(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertTrue(statusbar::should_show(self::page('admin', 'admin-setting-manageauths')));
        $this->assertTrue(statusbar::should_show(self::page('mydashboard', 'my-index')));
        $this->assertTrue(statusbar::should_show(self::page('frontpage', 'site-index')));
        $this->assertTrue(statusbar::should_show(self::page('course', 'course-view-topics')));
    }

    /**
     * Never in layouts without normal page chrome.
     */
    public function test_hidden_without_page_chrome(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach (statusbar::EXCLUDED_LAYOUTS as $layout) {
            $this->assertFalse(statusbar::should_show(self::page($layout, 'login-index')), $layout);
        }
    }

    /**
     * Anyone who can't publish or pull never sees it.
     */
    public function test_hidden_for_everyone_else(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(statusbar::should_show(self::page('mydashboard', 'my-index')));

        $this->setGuestUser();
        $this->assertFalse(statusbar::should_show(self::page('frontpage', 'site-index')));
    }
}
