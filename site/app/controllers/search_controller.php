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
 *   Wil Clouser <wclouser@mozilla.com> (Original Author)
 *   Cameron Roy <licensing@justcameron.com>
 *   Frederic Wenzel <fwenzel@mozilla.com>
 *   Chris Pollett <cpollett@gmail.com>
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

uses('sanitize');

class SearchController extends AppController
{

    /**
     * Cake Requirement for php4
     * @var
     */
    var $name = 'Search';

    /**
     * Cake array for what tables this controller accesses
     */
    var $uses = array('Addon', 'Addontype', 'File', 'Translation', 'Platform', );
    var $beforeFilter = array('checkCSRF', 'getNamedArgs', '_checkSandbox');

    /**
     * Holds the sanitize component, used to clean variables in our custom queries
     * @var object
     */
    var $Sanitize;
    
    // helper for javascript links
    var $helpers = array('Javascript', 'Pagination', 'Time');
    
    // components to be used by this controller
    var $components = array('Image', 'Pagination', 'Search', "Versioncompare", "Amo");

    // view layout
    var $layout = 'mozilla';

    var $securityLevel = 'low';

    /**
     * Constructor.  Declared so we can initialize Sanitize.
     * 
     */
    function SearchController() {

        parent::__construct();

        $this->Sanitize = new Sanitize();
    }

    function beforeFilter() {

        $this->forceShadowDb();

        // Disable ACLs because this controller is entirely public.
        $this->SimpleAuth->enabled = false;
        $this->SimpleAcl->enabled = false;

        // If search is disabled, show appropriate error
        if ($this->Config->getValue('search_disabled') == 1
            && !$this->SimpleAcl->actionAllowed('*', '*', $this->Session->read('User'))) {
            
            $this->flash(_('search_disabled'), '/', 3);
            exit;
        }
    }

    function index() {
        global $valid_status, $app_shortnames;
        if (!empty($this->params['url']['q'])) {
            $_terms = $this->params['url']['q'];
        } else {
            $_terms = '';
        }
        $this->publish('search_terms', $_terms);
        
        if (!empty($this->params['url']['cat'])) {
            $category = explode(',', $this->params['url']['cat']);
            if (count($category) != 2 || !is_numeric($category[0]) ||
                !is_numeric($category[1]))
                $category = array(0,0);
        } else
            $category = array(0,0);
        $this->publish('category', $category);

        //if advanced search appid set, use it
        $appname = "";
        if (isset( $this->params['url']['appid']) && 
            in_array($this->params['url']['appid'], array_values($app_shortnames) ) ) {
            $appname = array_search($this->params['url']['appid'], $app_shortnames);
            $redirect = str_replace(APP_SHORTNAME, $appname, $_SERVER['REQUEST_URI']);
            
            if($this->params['url']['appid'] != APP_ID) { $this->redirect("http://".$_SERVER["HTTP_HOST"].$redirect, null, true); }
        }
        $this->publish('appid', APP_ID); //publish for element caching

        //if advanced search atype set, use it.
        $atype = -1;
        $addon_types = $this->Addontype->getNames();
        if (isset($this->params['url']['atype']) && 
            in_array($this->params['url']['atype'], array_keys($addon_types))) { $atype = $this->params['url']['atype']; }
        $this->publish('atype', $atype); //publish for element caching

        //if advanced search pid (platform id) set, use it.
        $pid = -1;
        $platforms = $this->Amo->getPlatformName();
        if (isset( $this->params['url']['pid']) && 
            in_array($this->params['url']['pid'], array_keys($platforms))) { $pid = $this->params['url']['pid']; }
        $this->publish('pid', $pid); //publish for element caching
        
        //if advanced search last update requirement set, use it
        $lup = "";
        $updates = array('- INTERVAL 1 DAY', '- INTERVAL 1 WEEK', '- INTERVAL 1 MONTH', '- INTERVAL 3 MONTH', '- INTERVAL 6 MONTH', '- INTERVAL 1 YEAR');

        if (isset( $this->params['url']['lup']) && 
            in_array($this->params['url']['lup'], $updates) ) { $lup = $this->params['url']['lup']; }
        $this->publish('lup', $lup); //publish for element caching

        //if advanced search sort_order set, use it
        $sort = "";
        $sort_orders = array('newest', 'name', 'averagerating', 'weeklydownloads');
        if (isset( $this->params['url']['sort']) && 
            in_array($this->params['url']['sort'], $sort_orders) ) { $sort = $this->params['url']['sort']; }
        $this->publish('sort', $sort); //publish for element caching

        //if advanced search hver and lver set (for version range), use the
        $hver = $lver = -1;
        $vfuz = false;
        if (isset($this->params['url']['lver']) && isset( $this->params['url']['hver']) && isset( $this->params['url']['vfuz'])) { 
            $hver = $this->params['url']['hver'];
            $lver = $this->params['url']['lver'];
            $vfuz = $this->params['url']['vfuz'];
        }
        $this->publish('hver', $hver); //publish for element caching
        $this->publish('lver', $lver);
        $this->publish('vfuz', $vfuz); 
        
        // execute this search
        $_result_ids = $this->Search->search($_terms, $category[0], $category[1], NULL, $lver, $hver, $vfuz, $atype, $pid, $lup, $sort);
        
        if ($this->params['action'] != 'rss') {
            $this->pageTitle = _('search_pagetitle').' :: '.sprintf(_('addons_home_pagetitle'), APP_PRETTYNAME);
            $this->publish('cssAdd', array('forms'));
            $this->params['url']['q'] = urlencode( $this->params['url']['q']);
           
            $this->Pagination->total = count($_result_ids);
            
            //if advanced search pagination set, use it.
            $pp= 20;
            if (isset( $this->params['url']['pp']) && in_array($this->params['url']['pp'], $this->Pagination->resultsPerPage)) {
                 $pp = $this->Pagination->show = $this->params['url']['pp'];
            }
            $this->publish('pp',  $pp); //publish for element caching
            
            list($order,$limit,$page) = $this->Pagination->init();
            
            // cut the appropriate slice out of the results array
            $offset = ($page-1)*$limit;
            $_result_ids = array_slice($_result_ids, $offset, $limit);
        
            if (!empty($_terms)) {
                $rssurl = '/search/rss/?q='.urlencode($_terms);
                $this->publish('rssAdd', array($rssurl));
                $this->publish('bookmark_url', $rssurl);
            }
            
            if (!empty($_result_ids)) {
                $results = $this->Addon->getListAddons($_result_ids, $valid_status,
                    'FIELD(Addon.id,'.implode(',', $_result_ids).')', true);
            } else {
                $results = array();
            }
            
            $this->publish('bigHeader', true);
            $this->publish('bigHeaderText', sprintf(_('addons_home_header_details'), APP_PRETTYNAME));
            // Collapse categories menu, unless no results were found
            $this->publish('collapse_categories', !empty($results));
            
            /* pull in platforms for install button */
            $this->Platform->unbindFully();
            $platforms = $this->Platform->findAll();
            $this->publish('platforms', $platforms);
            
            $this->publish('search_results', $results);
            $this->forceCache();
            $this->render();
            return;

        } else {
            if (!empty($_result_ids)) {
                $_search_ids = array_slice($_result_ids, 0, 20);
                $results = $this->Addon->getListAddons($_search_ids, $valid_status,
                    'FIELD(Addon.id,'.implode(',', $_search_ids).')');
            } else {
                $results = array();
            }
            $this->publish('search_results', $results);
            $this->publish('rss_title', sprintf(_('search_rss_results_for'), $_terms));
            $this->publish('rss_description', _('search_rss_description'));
            $this->render('rss/index', 'rss');
            return;
        }
        

    }

    /**
     * Show RSS feed for given search
     */
    function rss() {
        return $this->index();  // let index handle rss action
    }

}

?>
