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
 * This module handles the display and events of the Snap! editor.
 *
 * @module     assignsubmission_snap/snap
 * @package    assignsubmission_snap
 * @copyright  2020 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';

let snapMode = 'edit';
let userid = 0;
let attemptNumber = 0;

export const init = (xmlProject, mode, user, attempt) => {
    snapMode = mode;
    userid = user;
    attemptNumber = attempt;

    let txt = document.createElement('textarea');
    txt.innerHTML = xmlProject;
    initializeProject(txt.value, snapMode, userid, attemptNumber);
};

const initializeProject = (xmlProject, snapMode, userid, attemptNumber) => {
    // Load the xmlProject (if not empty).
    const snapIDE = getIDE(xmlProject, snapMode, userid, attemptNumber);
    if (!xmlProject) {
        xmlProject = getXMLProject();
    }

    if (snapIDE) {
        if (xmlProject) {
            // Update the XML project.
            snapIDE.rawOpenProjectString(xmlProject);
        }
        if (snapMode == 'embed') {
            enableEmbedMode(snapIDE);
        } else if (snapMode == 'edit') {
            // Register events (to update the hiddent xmlproject field when the form is submitted).
            const form = getXMLInput().closest('form');
            registerListenerEvents(form);
        } else {
            preventWindowChanges();
        }

        // Customize Snap!, to hide Cloud options.
        customizeSnap();
    }
};

const getSnapFrame = (mode, user, attempt) => {
    if (!mode) {
        mode = snapMode;
    }
    if (!user) {
        user = userid;
    }
    if (!attempt) {
        attempt = attemptNumber;
    }
    return document.getElementById('snap-' + mode + '-' + user + '-' + attempt);
};

const getIDE = (xmlProject, snapMode, userid, attemptNumber) => {
    const snapFrame = getSnapFrame(snapMode, userid, attemptNumber);
    if (snapFrame) {
        const snapWorld = snapFrame.contentWindow.world;
        if (!snapWorld) {
            snapFrame.addEventListener(
                'load',
                function() {
                    initializeProject(xmlProject, snapMode, userid, attemptNumber);
                },
                true
            );
        } else {
            return snapWorld.children[0];
        }
    }

    return;
};

const getXMLProject = () => {
    let xmlProject = '';

    const xmlInput = getXMLInput();
    if (xmlInput) {
        xmlProject = getXMLInput().value;
    }

    return xmlProject;
};

const getXMLInput = () => {
    return $('input[name="xmlproject"]')[0];
};

const registerListenerEvents = (form) => {
    form.addEventListener('submit', updateProject);
};

const enableEmbedMode = (snapIDE) => {
    snapIDE.setEmbedMode();
    snapIDE.toggleAppMode(true);
    preventWindowChanges();
};

const preventWindowChanges = () => {
    const snapFrame = getSnapFrame();
    snapFrame.contentWindow.onbeforeunload = null;
};

const customizeSnap = () => {
/*
    const snapFrame = getSnapFrame();

    // It would disable the Cloud menu (but it doesn't hide it).
    // For now, it will be leave as it is (because it might help users to find and load their Snap! Cloud projects).
    snapFrame.contentWindow.IDE_Morph.prototype.cloudMenu = function () {
        this.showMessage('Cloud unavailable from Snap! assignment submission');
        return;
    };
*/
};

const updateProject = () => {
    const xmlProject = getXMLInput();
    const snapIDE = getIDE(xmlProject);
    xmlProject.value = snapIDE.serializer.serialize(snapIDE.stage);
    preventWindowChanges();
};
