n$F#NTfXnvcvP?mp&eCxlZ2kGVLOqE#@<?php
# Revision : $Rev: 1002 $

require_once ("STAMP.php");
STAMP::LibInclude('tracker','seeder','unit','bencode','xmlparser');
STAMP::setLogfile($STAMPconfig['dir']['DirPathLog']."/DepotLoader2.log");
STAMP::setLogLevel(3); # 2= only summeries and news, 3= One Line per package

$interval = 5; # in Minutes
$loop     = TRUE;

do {
    STAMP::PrintLog("Loop started at ".time(), 'MAIN', 2);
    //Below code commented by sourabh on 17-5-2024 for achieving cax_traces cleanup new task scheduler created start here 
	//EventTracer::RemoveOutdatedTraces();
	//End here
    // connect Database (opening and closing is important for Daemon scripts, b/c of backups)    
    global $STAMPconfig;

	//mysqli connect
	$mysqli = new mysqli($STAMPconfig['database']['host'],  $STAMPconfig['database']['user'], $STAMPconfig['database']['pass'],$STAMPconfig['database']['name']);
	// Check connection
	if ($mysqli -> connect_errno) {
	  echo "could not select DB: " . $mysqli -> connect_error;
	  exit();
	}  
	$result = $mysqli;  
    
    // Caches
    $package_objects = Array();
    $seeder_cats     = Array();
    
    
    // loop over tracker.
    foreach (Tracker::GetAllTrackers() as $tracker) {
        // object that represents the core-seeder
        // Refresh once per tracker to get changes done for last Tracker (e.g. added Torrents).
        $core_seeder = new Seeder($STAMPconfig['coreseeder']);
        $core_cat    = $core_seeder->SOAP('bt_get_progress');
        if ($core_cat === false) {
            STAMP::PrintLog("Could not fetch List from CoreSeeder!", 'ERROR', 1);
            exit;
        } else {
            STAMP::PrintLog("Cat of core-seeder contains ".count((array)$core_cat)." elements.", 'MAIN', 2);
        } 
        
        STAMP::PrintLog("---- TRACKER checking $tracker ----", 'MAIN', 2);
        $trac_obj         = new Tracker($tracker);
        $trac_cat         = $trac_obj->SOAP('tracker_get_cat');
        $setpoints        = Array();
        $seeder_setpoints = Array();
        $tor_url          = FALSE;
        
        if ($trac_cat === FALSE) {
            STAMP::PrintLog("Could not fetch Cat from tracker $tracker!", 'ERROR', 1);
            continue;
        } else {
            STAMP::PrintLog("Cat of tracker $tracker contains ".count($trac_cat)." elements.", 'MAIN', 2);
        }
    
        foreach ($trac_obj->GetNeededPackages() as $pkg_id) {
            $package_objects[$pkg_id] = $package_objects[$pkg_id] ? $package_objects[$pkg_id] : new Package($pkg_id);
			STAMP::PrintLog("\tPackage id Number : $pkg_id", 'CORESEEDER', 3);
            $fqpn = $package_objects[$pkg_id]->GetFQPN();
            $tor  = $package_objects[$pkg_id]->GetTorrentName();
            STAMP::PrintLog("----------> $fqpn @ $tracker", 'TRACKER', 4);
    
            $bt_full_url = $trac_obj->GetTorrentUrl($package_objects[$pkg_id]);        
            if ($bt_full_url) {
                array_push($setpoints, $bt_full_url);

                if (isset($trac_cat[$tor]) && ($trac_cat[$tor]['seeds'] > 1)) {
                    STAMP::PrintLog("\tNumber of Seeds for $tor: ".$trac_cat[$tor]['seeds'].'- not adding to CoreSeed.', 'CORESEEDER', 3);
                    $tor_url = $package_objects[$pkg_id]->GetTorrentUrl($tracker);
                } else {
                    STAMP::PrintLog("\t Number of seeds below minimum. Try to find help.", 'PRELOAD', 3);
                    if (isset($core_cat->{$tor}->{'state'})) {
                        STAMP::PrintLog("\t Torrent is listed at Core Seeder. Checking in Detail.", 'PRELOAD', 4);
                        if (($core_cat->{$tor}->{'state'} != 'Seeding') && ($core_cat->{$tor}->{'state'} != 'Queued for checking')) { 
                           # Assumption: Tracker at this file is not (no longer) seeding the package ?
                           STAMP::PrintLog("\t Status of $fqpn @ Core Seeder is ".$core_cat->{$tor}->{'state'}, 'WARNING', 2);
                           STAMP::PrintLog("\t Changing Tracker for CoreSeed to $tracker", 'WARNING', 2);
						   STAMP::PrintLog("\t GetAnnounceUrl ".$trac_obj->GetAnnounceUrl(), 'DEBUG1', 2);
						   STAMP::PrintLog("\t tor : ".$tor, 'DEBUG1', 2);
                           $core_seeder->SOAP('bt_change_tracker', Array($tor,$trac_obj->GetAnnounceUrl()));

                        } else {
                            # Extract the name of the tracker currently tracking the seed @ CoreSeeder.
                            $announce = $core_cat->{$tor}->{'tracker'};
							STAMP::PrintLog("\t on line 83 $announce before preg_match", 'PRELOAD', 2);
                            preg_match('/^http:\/\/([^\/]+)/',$announce,$matches);
                            $tor_url = $package_objects[$pkg_id]->GetTorrentUrl($matches[1]);
							STAMP::PrintLog("\t on line 86 $tor_url after preg_match", 'PRELOAD', 2);
                            if (! $tor_url) {
                               STAMP::PrintLog("\t No url for $tor out of ".$matches[1], 'PRELOAD', 2);
                               continue;
                            }
                            STAMP::PrintLog("\t Fetching Package $tor from swarm managed by ".$matches[1], 'PRELOAD', 2);
                        }
                    } else {
                        # No Seed to change - Add with Tor_Url of current tracker.
                        STAMP::PrintLog("\t Adding $tor to CoreSeeder for preloading.", 'PRELOAD', 2);
                        $tor_url = $package_objects[$pkg_id]->GetTorrentUrl($tracker);
						STAMP::PrintLog("\t on line 95 $tor_url before bt_start", 'PRELOAD', 2);
                        $core_seeder->SOAP('bt_start', Array($tor_url));
                    }
                }
				STAMP::PrintLog("\tPackage id Number : $pkg_id and  $tor_url", 'CORESEEDER', 3);
                if ($tor_url) array_push($seeder_setpoints,$tor_url);
                
            } else
               STAMP::PrintLog("\t could not create url for $fqpn @ $tracker", 'ERROR', 1);
    
        }
        foreach ($trac_obj->GetSeeder() as $seeder) {
            $seeder_obj = new Seeder($seeder);
            STAMP::PrintLog("\t sending must-haves down to $seeder. Elements:".count($seeder_setpoints), 'SEEDER', 1);
			STAMP::PrintLog("\t sending must-haves down to $seeder. Elements:".print_r($seeder_setpoints, TRUE), 'SEEDER', 1);
            $seeder_obj->SOAP('seeder_selfcheck',$seeder_setpoints);
        }
        STAMP::PrintLog("\t sending must-haves down to $tracker. Elements:".count($setpoints), 'TRACKER', 1);
        $trac_obj->SOAP('tracker_selfcheck',$setpoints);
    }
    
    // Last (and expensive)Step: Loop over the torrents of the core seeder and remove seeds no longer needed.
    STAMP::PrintLog("Checking Core Seeder for unneeded Packages", 'CORESEEDER', 2);
	
    $core_seeder    = new Seeder($STAMPconfig['coreseeder']);
    $core_cat       = $core_seeder->SOAP('bt_get_progress');
	
	
	
    $trac_cat_cache = array();	
	STAMP::PrintLog("core cat". print_r($core_cat, TRUE), 'CORESEEDER', 2);
    if ($core_cat === FALSE) {
        STAMP::PrintLog("Unable to fetch Cat from CoreSeeder!", 'ERROR', 1);
    } elseif (is_array($core_cat) || is_object($core_cat)) {
        foreach ($core_cat as $tor => $data) {
            preg_match('@^http://([^/]+)@',$data->{'tracker'},$matches);
            $tracker = $matches[1];
			if (! $tracker) {
			 STAMP::PrintLog("Failed to extract Tracker from ".$data->{'tracker'}, 'ERROR', 1);
			 continue;
			}
            STAMP::PrintLog("Hosting Torrent $tor at Tracker $tracker. Status:".$data->{'state'}, 'CORESEEDER', 3);
            if (! isset($trac_cat_cache[$tracker])) {
                STAMP::PrintLog("Fetching List from $tracker.", 'CORESEEDER', 2);
                $trac_obj = new Tracker($tracker);
                $trac_obj->SOAP('tracker_cleanup_database'); // Trigger a DB-Cleanup before fetching Info...
                $trac_cat_cache[$tracker] = $trac_obj->SOAP('tracker_get_cat');
                STAMP::PrintLog(print_r($trac_cat_cache[$tracker],TRUE), 'TRACKER_CAT', 5);
            }
            if ($trac_cat_cache[$tracker] === FALSE) {
                STAMP::PrintLog("Unable to fetch Cat from Tracker $tracker!", 'ERROR', 1);
            } else {
                if (isset($trac_cat_cache[$tracker][$tor])) {
                    $trac_data = $trac_cat_cache[$tracker][$tor];
                    STAMP::PrintLog("Number of Seeders at $tracker : ".$trac_data['seeds'], 'CORESEEDER', 3);
                    if ($trac_data['seeds'] > 5) {
                        STAMP::PrintLog($trac_data['seeds']." seeds available for Torrent $tor at $tracker.Stopping.", 'CORESEEDER', 2);
                        $core_seeder->SOAP('bt_stop',Array($tor));
                    }
                } else {
                    STAMP::PrintLog("$tor not known at $tracker, stopping Seed.", 'CORESEEDER', 2);
                    $core_seeder->SOAP('bt_stop',Array($tor));
                }
            }
        }
    } else {
        STAMP::PrintLog("CoreSeeder did not return an array!", 'ERROR', 1);
        //STAMP::PrintLog(print_r($core_cat,TRUE), 'ERROR', 1);
    }
    STAMP::PrintLog("zzzzzzzzzzzzzz111111 Script sleeping zzzzzzzzzzzzzz", 'MAIN', 1);
    //mysql_close(); # Always close the DB-Connection, this ensures valid connections after DB-Backup.
	mysqli_close($mysqli);
    sleep($interval * 60);
} while ($loop);
?>