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
 * This file contains the definition for the library class for Snap! submission plugin.
 *
 * @package   assignsubmission_snap
 * @copyright 2020 Sara Arjona <sara@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Library class for Snap! submission plugin extending submission plugin base class.
 *
 * @package   assignsubmission_snap
 * @copyright 2020 Sara Arjona <sara@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_snap extends assign_submission_plugin {

    /**
     * Get the name of the Snap! submission plugin.
     * @return string
     */
    public function get_name(): string {
        return get_string('snap', 'assignsubmission_snap');
    }

    /**
     * Get the settings for Snap! submission plugin. They are displayed in the assignment edit form.
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform): void {
        // TODO: Add iframe size (width/height).
    }

    /**
     * Save the settings for Snap! submission plugin.
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data): bool {
        // TODO: Save settings.

        return true;
    }

    /**
     * Add form elements for settings.
     *
     * @param mixed $submission can be null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return true if elements were added to the form
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {

        $xmlproject = $this->get_xmlproject($submission);
        if (!$xmlproject) {
            $template = $this->get_xmlproject_template();
            if ($template) {
                $xmlproject = $template;
            }
        }

        // Add the Snap! iframe.
        $mform->addElement('hidden', 'xmlproject', $xmlproject);
        $mform->setType('xmlproject', PARAM_RAW);

        $html = $this->get_view_snapframe($submission->userid, $submission->attemptnumber);
        $mform->addElement('html', $html);

        return true;
    }

    /**
     * Save data to the database and trigger plagiarism plugin,
     * if enabled, to scan the uploaded content via events trigger.
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data): bool {
        global $DB, $USER;

        $params = [
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submission->id,
            'other' => array(
                'content' => trim($data->xmlproject),
                'pathnamehashes' => [],
            )
        ];

        if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
            $params['relateduserid'] = $submission->userid;
        }
        if ($this->assignment->is_blind_marking()) {
            $params['anonymous'] = 1;
        }

        // Trigger assessable_uploaded event.
        $event = \assignsubmission_snap\event\assessable_uploaded::create($params);
        $event->trigger();

        // Get the group name as other fields are not transcribed in the logs and this information is important.
        $groupname = null;
        $groupid = 0;
        if (empty($submission->userid) && !empty($submission->groupid)) {
            $groupname = $DB->get_field('groups', 'name', ['id' => $submission->groupid], MUST_EXIST);
            $groupid = $submission->groupid;
        } else {
            $params['relateduserid'] = $submission->userid;
        }

        // Adapt $params to be used for the submisssion_xxxxx events.
        unset($params['objectid']);
        unset($params['other']);
        $params['other'] = array(
            'submissionid' => $submission->id,
            'submissionattempt' => $submission->attemptnumber,
            'submissionstatus' => $submission->status,
            'groupid' => $groupid,
            'groupname' => $groupname
        );

        $snapsubmission = $this->get_snap_submission($submission->id);
        if ($snapsubmission) {
            // Update existing Snap! submission.
            $snapsubmission->xmlproject = $data->xmlproject;
            $status = $DB->update_record('assignsubmission_snap', $snapsubmission);
            // Trigger submission updated event.
            $params['objectid'] = $snapsubmission->id;
            $event = \assignsubmission_snap\event\submission_updated::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
        } else {
            // Create a new Snap! submission.
            $snapsubmission = new stdClass();
            $snapsubmission->xmlproject = $data->xmlproject;
            $snapsubmission->submission = $submission->id;
            $snapsubmission->assignment = $this->assignment->get_instance()->id;
            $snapsubmission->id = $DB->insert_record('assignsubmission_snap', $snapsubmission);
            $status = $snapsubmission->id > 0;
            // Trigger submission created event.
            $params['objectid'] = $snapsubmission->id;
            $event = \assignsubmission_snap\event\submission_created::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
        }

        return $status;
    }

    /**
     * Remove a submission.
     *
     * @param stdClass $submission The submission.
     * @return boolean
     */
    public function remove(stdClass $submission): bool {
        global $DB;

        $submissionid = $submission ? $submission->id : 0;
        if ($submissionid) {
            $DB->delete_records('assignsubmission_snap', ['submission' => $submissionid]);
        }

        return true;
    }

    /**
     * Display Snap! result in the submission status table.
     *
     * @param stdClass $submission
     * @param bool $showviewlink - If the summary has been truncated set this to true
     * @return string
     */
    public function view_summary(stdClass $submission, & $showviewlink): string {
        $html = '';

        $snapsubmission = $this->get_snap_submission($submission->id);
        if ($snapsubmission) {
            // Always display the view link.
            // The player will be displayed with view_summary() and the full Snap! editor will be displayed with view().
            $showviewlink = true;

            $xmlproject = $this->get_xmlproject($submission);
            $html = $this->get_view_snapframe($submission->userid, $submission->attemptnumber,
                'embed', $xmlproject, '40%', '400px');
        }

        return $html;
    }

    /**
     * Display the saved content from the editor in the view table.
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission): string {
        // It will display always the full editor.
        $xmlproject = $this->get_xmlproject($submission);
        $html = $this->get_view_snapframe($submission->userid, $submission->attemptnumber, 'noedit', $xmlproject);

        return $html;
    }

    /**
     * Produce a list of files suitable for export that represent this submission.
     *
     * @param stdClass $submission - For this is the submission data
     * @param stdClass $user - This is the user record for this submission
     * @return array - return an array of files indexed by filename
     */
    public function get_files(stdClass $submission, stdClass $user): array {
        global $DB;

        $files = [];

        $snapsubmission = $this->get_snap_submission($submission->id);
        if ($snapsubmission) {
            $filename = get_string('snapfilename', 'assignsubmission_snap');
            $files[$filename] = [$snapsubmission->xmlproject];
        }

        return $files;
    }

    /**
     * The assignment has been deleted - remove the plugin specific data.
     *
     * @return bool
     */
    public function delete_instance(): bool {
        global $DB;

        $DB->delete_records('assignsubmission_snap',
                            ['assignment' => $this->assignment->get_instance()->id]);

        return true;
    }

    /**
     * Is this assignment plugin empty?
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission): bool {
        $snapsubmission = $this->get_snap_submission($submission->id);

        return empty($snapsubmission->xmlproject);
    }

    /**
     * Determine if a submission is empty.
     *
     * This is distinct from is_empty in that it is intended to be used to
     * determine if a submission made before saving is empty.
     *
     * @param stdClass $data The submission data
     * @return bool
     */
    public function submission_is_empty(stdClass $data): bool {
        return empty($data->xmlproject);
    }

    /**
     * Get file areas returns a list of areas this plugin stores files.
     *
     * @return array - An array of fileareas (keys) and descriptions (values)
     */
    public function get_file_areas(): array {
        // For now, this plugin doesn't store any file.
        return [];
    }

    /**
     * Copy the student's submission from a previous submission.
     * Used when a student opts to base their resubmission on the last submission.
     *
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     * @return bool True
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission): bool {
        global $DB;

        // Copy the assignsubmission_snap record.
        $snapsubmission = $this->get_snap_submission($sourcesubmission->id);
        if ($snapsubmission) {
            unset($snapsubmission->id);
            $snapsubmission->submission = $destsubmission->id;
            $DB->insert_record('assignsubmission_snap', $snapsubmission);
        }
        return true;
    }

    /**
     * Return a list of the text fields that can be imported/exported by this plugin.
     *
     * @return array An array of field names and descriptions. (name=>description, ...)
     */
    public function get_editor_fields(): array {
        return ['snap' => get_string('pluginname', 'assignsubmission_snap')];
    }

    /**
     * Get the saved text content from the editor.
     *
     * @param string $name
     * @param int $submissionid
     * @return string
     */
    public function get_editor_text($name, $submissionid): string {
        if ($name == 'snap') {
            $snapsubmission = $this->get_snap_submission($submissionid);
            if ($snapsubmission) {
                return $snapsubmission->xmlproject;
            }
        }

        return '';
    }

    /**
     * Get the content format for the editor.
     *
     * @param string $name
     * @param int $submissionid
     * @return int
     */
    public function get_editor_format($name, $submissionid): int {
        if ($name == 'snap') {
            $snapsubmission = $this->get_snap_submission($submissionid);
            if ($snapsubmission) {
                return FORMAT_PLAIN;
            }
        }

        return 0;
    }

    /**
     * Get submission information from the database.
     *
     * @param  int $submissionid
     * @return mixed
     */
    private function get_snap_submission(int $submissionid) {
        global $DB;

        return $DB->get_record('assignsubmission_snap', ['submission' => $submissionid]);
    }

    /**
     * Return the Snap! project wrapped iframe.
     *
     * There are several modes supported:
     *   - edit: the editor is displayed. Before leaving the page, a warning is displayed to avoid loose changes.
     *   - no edit: the full editor is displayed but no warning is displayed when the user leaves the page.
     *   - embed: only the player with the Snap! content is displayed (so no scripts & friends are displayed).
     *
     * @param  string $userid The author of this Snap! content.
     * @param  string $attempt The attepmt number.
     * @param  string $mode 'edit', 'noedit' or 'embed'.
     * @param  string $xmlproject The XML with the Snap! project to display.
     * @param  string $width iframe width.
     * @param  string $height  iframe height.
     * @return string the iframe to display the Snap! content.
     */
    private function get_view_snapframe(string $userid, string $attempt, string $mode = 'edit', string $xmlproject = null,
            string $width = '100%', string $height = '800px'): string {
        global $CFG, $OUTPUT, $USER;

        // TODO: Check if it's possible to hide menus in Snap!.

        $template = new \stdClass();
        $template->snapurl = "$CFG->wwwroot/mod/assign/submission/snap/snaplib/snap.html";
        if ($xmlproject) {
            $template->xmlproject = $xmlproject;
        }
        if (!$userid) {
            $userid = $USER->id;
        }
        $template->userid = $userid;
        $template->attempt = $attempt;
        $template->snapmode = $mode;
        $template->width = $width;
        $template->height = $height;

        $html = $OUTPUT->render_from_template('assignsubmission_snap/snapiframe', $template);

        return $html;
    }

    /**
     * Return the XML project from a Snap! submission.
     *
     * @param  stdClass $submission The submission
     * @return string The XML project of the Snap! content.
     */
    private function get_xmlproject(stdClass $submission): string {
        $xmlproject = '';
        if ($submission) {
            $snapsubmission = $this->get_snap_submission($submission->id);
            if ($snapsubmission) {
                $xmlproject = $snapsubmission->xmlproject;
            }
        }

        return $xmlproject;
    }

    /**
     * Get if the assignment has attached any XML Snap! file. If exists, it will be used as template for new submissions,
     * instead of open an empty Snap! project.
     *
     * @return string The XML project to use as template (if exists).
     */
    private function get_xmlproject_template(): string {
        $fs = get_file_storage();
        $fsfiles = $fs->get_area_files($this->assignment->get_context()->id,
                                       'mod_assign',
                                       'introattachment',
                                       false,
                                       null,
                                       false);
        foreach ($fsfiles as $file) {
            if ($file->get_mimetype() == 'application/xml') {
                $content = $file->get_content();
                if (strpos($content, 'app="Snap!')) {
                    // This is a Snap! file. Use it as template (instead of displaying an empty project to the students).
                    return $content;
                }
            }
        }

        return '';
    }

}
