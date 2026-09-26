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

/**
 * Language strings for auth_nucleus.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['auth_nucleusdescription'] = 'People sign in with their account on the federation\'s hub. Nucleus sets this up when the federation turns on sign-in with the hub. Site administrators keep their local accounts.';
$string['cachedef_jwks'] = 'The hub\'s public signing keys';
$string['defaulthubname'] = 'your hub';
$string['emailpasswordchangeinfo'] = 'Hi {$a->firstname},

Someone (probably you) asked to reset the password for your account on \'{$a->sitename}\'.

You sign in to this site with {$a->hubname}, so there\'s no password to reset here. Reset your password on {$a->hubname} instead, or ask its administrator for help.

{$a->admin}';
$string['emailpasswordchangeinfosubject'] = '{$a}: Change password information';
$string['error_accessdenied'] = 'Your hub account can\'t be used to sign in here. Contact the site administrator.';
$string['error_account'] = 'Your account on this site can\'t be used to sign in with the hub. Contact the site administrator.';
$string['error_badtoken'] = 'The hub\'s reply couldn\'t be verified, so you weren\'t signed in. Try again.';
$string['error_claims'] = 'The hub didn\'t send the name and email address this site needs, so you weren\'t signed in. Contact the site administrator.';
$string['error_emailexists'] = 'There\'s already an account for {$a} on this site. Ask the site\'s administrator to link it to your hub account in Nucleus.';
$string['error_generic'] = 'Something went wrong signing you in. Try again.';
$string['error_hubrefused'] = 'The hub didn\'t sign you in. Try again.';
$string['error_hubunreachable'] = 'This site couldn\'t reach the hub to finish signing you in. Try again in a minute.';
$string['error_linknotconfigured'] = 'Sign-in with the hub isn\'t set up on this site, so accounts can\'t be linked yet.';
$string['error_nocreate'] = 'This site doesn\'t create accounts at sign-in, and you don\'t have one yet. Contact the site administrator.';
$string['error_notenabled'] = 'Signing in with the hub isn\'t turned on for this site.';
$string['error_privileged'] = 'Accounts with site-wide roles can\'t sign in with the hub. Use a local account instead.';
$string['error_siteadmin'] = 'Site administrators can\'t sign in with the hub. Use a local account instead.';
$string['error_state'] = 'That sign-in had expired or was already used. Try again.';
$string['localaccountnote'] = 'This form is for local accounts, such as the site administrator\'s. Everyone else signs in with {$a}.';
$string['pluginname'] = 'Nucleus hub sign-in';
$string['privacy:metadata:coreauth'] = 'Nucleus hub sign-in uses Moodle\'s authentication to sign people in.';
$string['privacy:metadata:hub'] = 'The federation\'s hub. At sign-in the hub sends the person\'s name, email address and language, which update their account on this site. At sign-out, their browser returns the hub\'s sign-in token to the hub.';
$string['privacy:metadata:hub:idtoken'] = 'The hub\'s sign-in token, which identifies the person to the hub.';
$string['privacy:metadata:link'] = 'Which hub account each local account belongs to.';
$string['privacy:metadata:link:issuer'] = 'The hub the hub account belongs to.';
$string['privacy:metadata:link:previousauth'] = 'How the account signed in before it was linked, to restore when sign-in with the hub is turned off.';
$string['privacy:metadata:link:sub'] = 'The hub\'s identifier for the person.';
$string['privacy:metadata:link:timecreated'] = 'When the accounts were linked.';
$string['privacy:metadata:link:userid'] = 'The ID of the local account.';
$string['setting_autoredirect'] = 'Go straight to the hub';
$string['setting_autoredirect_desc'] = 'Send people from the login page straight to the hub. Local accounts sign in at /login/index.php?local=1.';
$string['setting_clientid'] = 'Client ID';
$string['setting_clientid_desc'] = 'This site\'s client ID on the hub.';
$string['setting_clientsecret'] = 'Client secret';
$string['setting_clientsecret_desc'] = 'This site\'s client secret on the hub. It\'s stored encrypted and never shown.';
$string['setting_hubname'] = 'Hub name';
$string['setting_hubname_desc'] = 'Shown on the login button: "Sign in with {hub name}".';
$string['setting_issuer'] = 'Hub issuer';
$string['setting_issuer_desc'] = 'The hub\'s sign-in address: exactly the hub URL set in Nucleus > Hub connection, followed by /local/nucleushub/oidc (for example https://hub.example.com/local/nucleushub/oidc). A hub that has moved to a new address keeps the sign-in address it had before, which Nucleus gives this site.';
$string['setting_locked'] = 'Profile fields';
$string['setting_locked_desc'] = 'Email address, first name and last name come from the hub. People can\'t change them here; the hub updates them at each sign-in.';
$string['setting_singlesignout'] = 'Sign out of the hub too';
$string['setting_singlesignout_desc'] = 'When someone with a hub account signs out of this site, sign them out of the hub as well.';
$string['settings_intro'] = 'Nucleus sets these when the federation turns on sign-in with the hub. Only change them if DK Labs asks you to.';
$string['settings_intro_hosted'] = 'Nucleus sets these when the federation turns on sign-in with the hub. They can\'t be changed here.';
$string['signinwith'] = 'Sign in with {$a}';
$string['uselocalaccount'] = 'Use a local account';
