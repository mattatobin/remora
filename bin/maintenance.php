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
 *   Mike Morgan <morgamic@mozilla.com>
 *   Andrei Hajdukewycz <sancus@off.net>
 *   Justin Scott <fligtar@mozilla.com>
 *   Frederic Wenzel <fwenzel@mozilla.com>
 *   Les Orchard <lorchard@mozilla.com>
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

/**
 * Maintenance script for addons.mozilla.org.
 *
 * The purpose of this document is to perform periodic tasks that should not be
 * done everytime a download occurs in install.php.  This should reduce
 * unnecessary DELETE and UPDATE queries and lighten the load on the database
 * backend.
 *
 * This script should not ever be accessed over HTTP, and instead run via cron.
 * Only sysadmins should be responsible for operating this script.
 *
 * @package amo
 * @subpackage bin
 */


// Before doing anything, test to see if we are calling this from the command
// line.  If this is being called from the web, HTTP environment variables will
// be automatically set by Apache.  If these are found, exit immediately.
if (isset($_SERVER['HTTP_HOST'])) {
    exit;
}

require_once('database.class.php');


/**
 *  * Get time as a float.
 *   * @return float
 *    */
function getmicrotime() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

// Start our timer.
$start = getmicrotime();

// New database class
$db = new Database();

// Get our action.
$action = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';

// Perform specified task.  If a task is not properly defined, exit.
switch ($action) {

    /**
     * Update weekly addon counts.
     */
    case 'weekly':
        // Lock stats dashboard
        $db->lockStats();

        $seven_day_counts = array();

        $addons_sql = "SELECT id FROM addons";
        echo "Retrieving all add-on ids...\n";
        $addons_result = $db->query($addons_sql, true);
        
        $affected_rows = mysql_num_rows($addons_result);
    
        if ($affected_rows > 0 ) {
            while ($row = mysql_fetch_array($addons_result)) {
                $seven_day_counts[$row['id']] = 0;
            }
        }
        
        // Get 7 day counts from the download table.
        $seven_day_count_sql = "
            SELECT
                download_counts.addon_id as addon_id,
                SUM(download_counts.count) as seven_day_count
            FROM
                `download_counts`
            WHERE
                `date` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY
                download_counts.addon_id
            ORDER BY
                download_counts.addon_id
        ";

        echo 'Retrieving seven-day counts from `download_counts` ...'."\n";
        $seven_day_count_result = $db->query($seven_day_count_sql);

        $affected_rows = mysql_num_rows($seven_day_count_result);
    
        if ($affected_rows > 0 ) {
            while ($row = mysql_fetch_array($seven_day_count_result)) {
                $seven_day_counts[$row['addon_id']] = ($row['seven_day_count']>0) ? $row['seven_day_count'] : 0;
            }

            echo 'Updating seven day counts in `main` ...'."\n";

            foreach ($seven_day_counts as $id=>$seven_day_count) {
                $seven_day_count_update_sql = "
                    UPDATE `addons` SET `weeklydownloads`='{$seven_day_count}' WHERE `id`='{$id}'
                ";

                $seven_day_count_update_result =
                $db->query($seven_day_count_update_sql, true);
            }
        }
        
        // Unlock stats dashboard
        $db->unlockStats();
    break;

    /**
     * Update total addon counts.
     */
    case 'total':
        // Lock stats dashboard
        $db->lockStats();
        
        // Get total counts from the download table.
        $total_count_sql = "
            SELECT
                download_counts.addon_id as addon_id,
                SUM(download_counts.count) as total_count
            FROM
                `download_counts`
            GROUP BY
                download_counts.addon_id
            ORDER BY
                download_counts.addon_id
        ";

        echo 'Retrieving total counts from `download_counts` ...'."\n";
        $total_count_result = $db->query($total_count_sql);

        $affected_rows = mysql_num_rows($total_count_result);
    
        if ($affected_rows > 0 ) {
            $total_counts = array();
            while ($row = mysql_fetch_array($total_count_result)) {
                $total_counts[$row['addon_id']] = ($row['total_count'] > 0) ? $row['total_count'] : 0;
            }

            foreach ($total_counts as $id => $total_count) {
                $total_count_update_sql = "
                    UPDATE `addons` SET `totaldownloads`='{$total_count}' WHERE `id`='{$id}'
                ";

                $total_count_update_result =
                $db->query($total_count_update_sql, true);
            }
        }
        
        // Unlock stats dashboard
        $db->unlockStats();
    break;

    /**
     * Garbage collection for all records that are older than 8 days.
     */
    case 'gc':
        echo 'Starting garbage collection ...'."\n";
        $affected_rows = 0;
        
        /* Disabling download count clean-up for better statistics
        echo 'Cleaning up download_counts table ...'."\n";
        $gc_sql = "
            DELETE FROM
                `download_counts`
            WHERE
                `date` < DATE_SUB(CURDATE(), INTERVAL 31 DAY)
        ";
        $gc_result = mysql_query($gc_sql, $write) 
            or trigger_error('MySQL Error '.mysql_errno().': '.mysql_error()."", 
                             E_USER_NOTICE);

        $affected_rows = mysql_affected_rows($write);*/

        echo 'Cleaning up sessions table ...'."\n";
        $session_sql = "
            DELETE FROM
                `cake_sessions`
            WHERE
                `expires` < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
        ";
        $session_result = $db->query($session_sql, true);

        $affected_rows += mysql_affected_rows($db->write);

    break;



    /**
     * Copy all public files to the public repository.
     * If files already exist, overwrite them.
     */
    case 'publish_files':
        echo 'Starting public file copy ...'."\n";

        $files_sql = "
            SELECT DISTINCT
                addons.id as addon_id,
                files.filename as filename
            FROM
                versions 
            INNER JOIN addons ON versions.addon_id = addons.id AND addons.status = 4 AND addons.inactive = 0
            INNER JOIN files ON files.version_id = versions.id AND files.status = 4
            ORDER BY
                addons.id DESC
        ";

        // Get file names and IDs of all files to copy.
        $files_result = $db->query($files_sql);
    
        $affected_rows = 0;

        while ($row = mysql_fetch_array($files_result)) {
            // For each valid file, copy it from REPO_PATH to STAGING_PUBLIC_PATH.
            if (copyFileToPublic($row['addon_id'],$row['filename'],true)) {
                echo 'Copy SUCCEEDED for add-on '.$row['addon_id'].' file '.$row['filename']."\n";
            } else {
                echo 'Copy FAILED for add-on '.$row['addon_id'].' file '.$row['filename']."\n";
            }
        }

    break;



    /**
     * Get review totals and update addons table.
     */
    case 'reviews':
        echo 'Starting review total updates...'."\n";
        
        $reviews_sql = "
            UPDATE addons AS a
            INNER JOIN (
                SELECT
                    versions.addon_id as addon_id,
                    COUNT(*) as count
                FROM reviews 
                INNER JOIN versions ON reviews.version_id = versions.id 
                WHERE reviews.reply_to IS NULL
                    AND reviews.rating > 0
                GROUP BY versions.addon_id 
            ) AS c ON (a.id = c.addon_id)
            SET a.totalreviews = c.count
        ";
        $reviews_result = $db->query($reviews_sql);

        $affected_rows = mysql_affected_rows($reviews_result);

    break;



    /**
     * Get average ratings and update addons table.
     */
    case 'ratings':
        echo 'Updating average ratings...'."\n";
        $rating_sql = "
            UPDATE addons AS a
            INNER JOIN (
                SELECT
                    versions.addon_id as addon_id,
                    AVG(rating) as avg_rating
                FROM reviews 
                INNER JOIN versions ON reviews.version_id = versions.id 
                WHERE reviews.reply_to IS NULL
                    AND reviews.rating > 0
                GROUP BY versions.addon_id 
            ) AS c ON (a.id = c.addon_id)
            SET a.averagerating = ROUND(c.avg_rating, 2)
        ";
        $rating_result = $db->query($rating_sql);

        echo 'Updating bayesian ratings...'."\n";
        // get average review count and average rating
        $rows = $db->query("
            SELECT AVG(a.cnt) AS avg_cnt 
            FROM (
                SELECT COUNT(*) AS cnt
                FROM reviews AS r
                INNER JOIN versions AS v ON (r.version_id = v.id)
                WHERE reply_to IS NULL
                    AND rating > 0
                GROUP BY v.addon_id
                ) AS a
        ");
        $row = mysql_fetch_array($rows);
        $avg_num_votes = $row['avg_cnt'];
        
        $rows = $db->query("
            SELECT AVG(a.addon_rating) AS avg_rating 
            FROM (
                SELECT AVG(rating) AS addon_rating
                FROM reviews AS r
                INNER JOIN versions AS v ON (r.version_id = v.id)
                WHERE reply_to IS NULL
                    AND rating > 0
                GROUP BY v.addon_id
                ) AS a
        ");
        $row = mysql_fetch_array($rows);
        $avg_rating = $row['avg_rating'];
        
        // calculate and store bayesian rating
        $rating_sql = "
            UPDATE addons AS a
            SET a.bayesianrating =
                IF (a.totalreviews > 0, (
                    ( ({$avg_num_votes} * {$avg_rating}) + (a.totalreviews * a.averagerating) ) /
                    ({$avg_num_votes} + a.totalreviews)
                ), 0)
        ";
        $rating_result = $db->query($rating_sql);

        $affected_rows = mysql_affected_rows();

    break;



    /**
     * Delete user accounts that have not been confirmed for two weeks
     */
    case 'unconfirmed':
        echo "Removing user accounts that haven't been confirmed for two weeks...\n";
        $unconfirmed_sql = "
            DELETE IGNORE 
            FROM users
            WHERE created < DATE_SUB(CURDATE(), INTERVAL 2 WEEK)
                AND confirmationcode != '';
        "; // using IGNORE keyword in order to not delete users who are referenced anywhere
        $res = $db->query($unconfirmed_sql);

        $affected_rows = mysql_affected_rows();
    break;



    /**
     * Delete password reset codes that have expired.
     */
    case 'expired_resetcode':
        echo "Removing reset codes that have expired...\n";
        $db->query("UPDATE users
                    SET resetcode=DEFAULT,
                        resetcode_expires=DEFAULT
                    WHERE resetcode_expires < NOW()");
        $affected_rows = mysql_affected_rows();
    break;



    /**
     * Update addon-collection download totals.
     */
    case 'addons_collections_total':
        echo "Starting addon_collection totals update...\n";
        $addons_collections_total = "
            UPDATE
                addons_collections AS ac
            INNER JOIN (
                SELECT
                    stats.addon_id as addon_id,
                    stats.collection_id as collection_id,
                    SUM(stats.count) AS sum
                FROM
                    stats_addons_collections_counts AS stats
                GROUP BY
                    stats.addon_id, stats.collection_id
            ) AS j ON (ac.addon_id = j.addon_id AND
                       ac.collection_id = j.collection_id)
            SET
                ac.downloads = j.sum
        ";
        $db->query($addons_collections_sql);
        $affected_rows = mysql_affected_rows();
    break;



    /**
     * Update collection downloads total.
     */
    case 'collections_total':
        echo "Starting collection totals update...\n";
        $collections_sql = "
            UPDATE
                collections AS c
            INNER JOIN (
                SELECT
                    stats.collection_id AS collection_id,
                    SUM(stats.count) AS sum
                FROM
                    stats_collections_counts AS stats
                GROUP BY
                    stats.collection_id
            ) AS j ON (c.id = j.collection_id)
            SET
                c.downloads = j.sum
        ";
        $db->query($collections_sql);
        $affected_rows = mysql_affected_rows();
    break;



    /**
     * Unknown command.
     */
    default:
        echo 'Command not found. Exiting ...'."\n";
        exit;
    break;
}
// End switch.



// How long did it take to run?
$exectime = getmicrotime() - $start;



// Display script output.
echo 'Affected rows: '.$affected_rows.'    ';
echo 'Time: '.$exectime."\n";
echo 'Exiting ...'."\n";



/**
* Copy a file to the rsync location for updates
* @param int $addon_id the add-on id
* @param string $filename the filename
* @param boolean $overwrite whether to overwrite the destination file
* @return boolean
*/
function copyFileToPublic($addon_id, $filename, $overwrite = true) {
    // Only copy if the path has been defined
    if (!defined('PUBLIC_STAGING_PATH')) {
        // return true because false indicates error
        return false;
    }
    
    $currentFile = REPO_PATH."/{$addon_id}/{$filename}";
    $newDir = PUBLIC_STAGING_PATH."/{$addon_id}";
    $newFile = $newDir."/{$filename}";
    
    // Make sure source file exists
    if (!file_exists($currentFile)) {
        return false;
    }
    
    // If we don't want to overwrite, make sure we don't
    if (!$overwrite && file_exists($newFile)) {
        // return true because this is not treated as an error
        return false;
    }
    
    // Make directory if necessary
    if (!file_exists($newDir)) {
        if (!mkdir($newDir)) {
            return false;
        }
    }
    
    return copy($currentFile, $newFile);
}



exit;
?>
