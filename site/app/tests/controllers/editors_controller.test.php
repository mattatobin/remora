<?php
/* ***** BEGIN LICENSE BLOCK *****
 * Version: MPL 1.1/GPL 2.0/LGPL 2.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is addons.mozilla.org site.
 *
 * The Initial Developer of the Original Code is
 * The Mozilla Foundation.
 * Portions created by the Initial Developer are Copyright (C) 2006
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 *   Wil Clouser <clouserw@mozilla.com> (Original Author)
 *
 * Alternatively, the contents of this file may be used under the terms of
 * either the GNU General Public License Version 2 or later (the "GPL"), or
 * the GNU Lesser General Public License Version 2.1 or later (the "LGPL"),
 * in which case the provisions of the GPL or the LGPL are applicable instead
 * of those above. If you wish to allow use of your version of this file only
 * under the terms of either the GPL or the LGPL, and not to allow others to
 * use your version of this file under the terms of the MPL, indicate your
 * decision by deleting the provisions above and replace them with the notice
 * and other provisions required by the GPL or the LGPL. If you do not delete
 * the provisions above, a recipient may use your version of this file under
 * the terms of any one of the MPL, the GPL or the LGPL.
 *
 * ***** END LICENSE BLOCK ***** */
class EditorsControllerTest extends WebTestHelper {

    var $testdata = array(
                            'addonid' => 7, // microfarmer
                            'tagid'   => 2, // viruses
                            'feature_locales' => 'en-US,fr,de'
                        );

	/**
	* Setup the Editors Controller
	*/
	function testLoad() {
        $this->WebTestCase('Editor Feature Tests');

        loadModel('Addon');
        $this->Addon =& new Addon();
        $this->Addon->caching = false;
        $this->Addon->cacheQueries = false;
	}

    function testAddFeature() {
        $this->assertFalse($this->_checkIfDataExists(), "Make sure the data we're going to use doesn't exist.");

        $this->login();
        $this->setMaximumRedirects(0);

        $this->getAction("/editors/featured/add/{$this->testdata['tagid']}/{$this->testdata['addonid']}/ajax");
        $this->assertResponse(array('200'), "Valid AJAX data gets appropriate response.");
        $this->assertTrue($this->_checkIfDataExists(), "Valid AJAX data gets inserted into the db");
        $this->_removeTestData();

        $this->getAction("/editors/featured/add/broken{$this->testdata['tagid']}/blah{$this->testdata['addonid']}/ajax");
        $this->assertResponse(array('400'), "Invalid AJAX data gets appropriate response.");

        $_data = array( 'data[Addon][id]' => 'Microfarmer [7]', 'data[Tag][id]' => 2);
        $this->post($this->actionURI("/editors/featured/add"),$_data);
        $this->assertResponse(array('200'), "Valid POST data gets appropriate response.");
        $this->assertTrue($this->_checkIfDataExists(), "Valid POST data gets inserted into the db");
        $this->_removeTestData();
        unset($_data);

        $_data = array( 'data[Addon][id]' => '7broken', 'data[Tag][id]' => '2broken');
        $this->post($this->actionURI("/editors/featured/add"),$_data);
        $this->assertResponse(array('400'), "Invalid POST data gets appropriate response.");
        unset($_data);

    }
    function testEditFeature() {
        $this->login();
        $this->setMaximumRedirects(0);

        $this->_addTestData();

        $_data = array('data[AddonTag][feature_locales]' => 'en-US,de');
        $this->post($this->actionURI("/editors/featured/edit/{$this->testdata['tagid']}/{$this->testdata['addonid']}/ajax"), $_data);
        $this->assertResponse(array('200'), "Valid AJAX edit request with valid data gets accepted.");
        $_ret = $this->Addon->execute("SELECT feature_locales FROM `addons_tags` WHERE addon_id={$this->testdata['addonid']} AND tag_id={$this->testdata['tagid']}");
        $this->assertEqual('de,en-US', $_ret[0]['AddonTag']['feature_locales'], 'Valid AJAX edit request changes data');
        unset($_data, $_ret);

        $_data = array('data[AddonTag][feature_locales]' => 'en-US,de');
        $this->post($this->actionURI("/editors/featured/edit/{$this->testdata['tagid']}broken/{$this->testdata['addonid']}broken/ajax"), $_data);
        $this->assertResponse(array('400'), "Invalid AJAX edit request with valid data gets rejected.");

        $_data = array('data[AddonTag][feature_locales]' => 'en-US,broken,de');
        $this->post($this->actionURI("/editors/featured/edit/{$this->testdata['tagid']}/{$this->testdata['addonid']}/ajax"), $_data);
        $this->assertResponse(array('400'), "Valid AJAX edit request with invalid data gets rejected.");

        $_data = array( 'data[Addon][id]' => 7, 'data[Tag][id]' => 2, 'data[AddonTag][feature_locales]' => 'en-US,fr');
        $this->post($this->actionURI("/editors/featured/edit"), $_data);
        $this->assertResponse(array('200'), "Valid POST edit request with valid data gets accepted.");
        $_ret = $this->Addon->execute("SELECT feature_locales FROM `addons_tags` WHERE addon_id={$this->testdata['addonid']} AND tag_id={$this->testdata['tagid']}");
        $this->assertEqual('en-US,fr', $_ret[0]['AddonTag']['feature_locales'], 'Valid POST edit request changes data');
        unset($_data, $_ret);

        $_data = array( 'data[Addon][id]' => 7, 'data[Tag][id]' => '2broken', 'data[AddonTag][feature_locales]' => 'en-US,fr');
        $this->post($this->actionURI("/editors/featured/edit"), $_data);
        $this->assertResponse(array('400'), "Valid POST edit request with invalid data gets rejected.");

        $_data = array( 'data[Addon][id]' => 7, 'data[Tag][id]' => 2, 'data[AddonTag][feature_locales]' => 'en-US,broken,fr');
        $this->post($this->actionURI("/editors/featured/edit"), $_data);
        $this->assertResponse(array('400'), "Valid POST edit request with invalid locales gets rejected.");

        $this->_removeTestData();
    }
    function testRemoveFeature() {
        $this->login();
        $this->setMaximumRedirects(0);

        $this->_addTestData();
        $this->getAction("/editors/featured/remove/{$this->testdata['tagid']}/{$this->testdata['addonid']}");
        $this->assertResponse(array('200'), "Valid GET removal request gets appropriate response.");
        $this->assertFalse($this->_checkIfDataExists(), "Valid GET removal request removes data.");

        $this->getAction("/editors/featured/remove/{$this->testdata['tagid']}broken/{$this->testdata['addonid']}broken");
        $this->assertResponse(array('400'), "Invalid GET removal request gets appropriate response.");
    }

    /**
     * We've got to use direct queries here because cake 1.1 doesn't support getting data in join tables. :-/
     */
    function _checkIfDataExists() {
        $ret = $this->Addon->execute("SELECT addon_id FROM `addons_tags` WHERE addon_id={$this->testdata['addonid']} AND tag_id={$this->testdata['tagid']}");
        return !empty($ret);
    }

    function _addTestData() {
        $this->Addon->execute("INSERT INTO `addons_tags` VALUES('{$this->testdata['addonid']}','{$this->testdata['tagid']}', 1, '{$this->testdata['feature_locales']}')");
    }

    function _removeTestData() {
        $this->Addon->execute("DELETE FROM `addons_tags` WHERE addon_id={$this->testdata['addonid']} AND tag_id={$this->testdata['tagid']} LIMIT 1");
    }
}
