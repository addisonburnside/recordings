<?php

// Source and Destination Dirctories for recording
global $recordings_astsnd_path; // PHP5 needs extra convincing of a global
$recordings_save_path = "/tmp/";
$recordings_astsnd_path = isset($asterisk_conf['astvarlibdir'])?$asterisk_conf['astvarlibdir']:'/var/lib/asterisk';
$recordings_astsnd_path .= "/sounds/";

function recordings_get_config($engine) {
	global $ext;  // is this the best way to pass this?
	global $recordings_save_path;
	
	$modulename = "recordings";
	$appcontext = "app-recordings";
	
	switch($engine) {
		case "asterisk":
			// FeatureCodes for save / check
			$fcc = new featurecode($modulename, 'record_save');
			$fc_save = $fcc->getCodeActive();
			unset($fcc);

			$fcc = new featurecode($modulename, 'record_check');
			$fc_check = $fcc->getCodeActive();
			unset($fcc);

			if ($fc_save != '' || $fc_check != '') {
				$ext->addInclude('from-internal-additional', 'app-recordings'); // Add the include from from-internal
				
				if ($fc_save != '') {
					$ext->add($appcontext, $fc_save, '', new ext_macro('user-callerid'));
					$ext->add($appcontext, $fc_save, '', new ext_wait('2'));
					$ext->add($appcontext, $fc_save, '', new ext_macro('systemrecording', 'dorecord'));
				}

				if ($fc_check != '') {
					$ext->add($appcontext, $fc_check, '', new ext_macro('user-callerid'));
					$ext->add($appcontext, $fc_check, '', new ext_wait('2'));
					$ext->add($appcontext, $fc_check, '', new ext_macro('systemrecording', 'docheck'));
				}
			}

			// Now generate the Feature Codes to edit recordings
			//
			$recordings = recordings_list();
			foreach ($recordings as $item) {

				// Get the feature code, and do a sanity check if it is not suppose to be active and delete it
				//
				if ($item['fcode'] != 0) {
					$fcc = new featurecode($modulename, 'edit-recording-'.$item['id']);
					$fcode = $fcc->getCodeActive();
					unset($fcc);
				} else {
					$fcc = new featurecode('recordings', 'edit-recording-'.$item['id']);
					$fcc->delete();
					unset($fcc);	
					continue; // loop back to foreach
				}

				if ($fcode != '') {
					// Do a sanity check, there should be no compound files
					//
					if (strpos($item['filename'], '&') === false && trim($item['filename']) != '') {
						$fcode_pass = (trim($item['fcode_pass']) != '') ? ','.$item['fcode_pass'] : '';
						$ext->add($appcontext, $fcode, '', new ext_macro('user-callerid'));
						$ext->add($appcontext, $fcode, '', new ext_wait('2'));
						$ext->add($appcontext, $fcode, '', new ext_macro('systemrecording', 'docheck,'.$item['filename'].$fcode_pass));
						//$ext->add($appcontext, $fcode, '', new ext_macro('hangup'));
					}
				}
			}
		break;
	}
}

function recordings_get_or_create_id($fn, $module) {
	$id = recordings_get_id($fn);
	if ($id != null) {
		return $id;
	} else {
		// Create the id, name it the file name or if multi-part ...
		//
		$dname = explode('&',$displayname);
		$displayname = 'auto-created: ';
		$displayname .= count($dname) == 1 ? $fn : $dname[0]."&...";
		$description = sprintf(_("Missing Sound file auto-created from migration of %s module"),$module);
		recordings_add($displayname, $fn, $description='');

		// get the id we just created
		//
		$id = recordings_get_id($fn);

		// Notify of issue
		//
		$nt =& notifications::create($db);
		$text = sprintf(_("Non-Existent Recording in module %s"),$module);
		$extext = sprintf(_("The %s referenced a recording file listed below that does not exists. An entry has been generated, named %s, with the referenced file(s) but you should confirm that it really works and the real files exist. The file(s) referenced: %s "),$module, $displayname, $fn);
		$nt->add_error('recordings', 'NEWREC-'.$id, $text, $extext, '', true, true);
		unset($nt);

		// return the id just created
		return $id;
	}
}

function recordings_get_id($fn) {
	global $db;
	
	$sql = "SELECT id FROM recordings WHERE filename='$fn'";
        $results = $db->getRow($sql, DB_FETCHMODE_ASSOC);
	if (isset($results['id'])) {
		return $results['id'];
	} else {
		return null;
	}
}

function recordings_get_file($id) {
	$res = recordings_get($id);
	return $res['filename'];
}
	

function recordings_list($compound=true) {
	global $db;

	// I'm not clued on how 'Department's' work. There obviously should be 
	// somee checking in here for it.

	$sql = "SELECT * FROM recordings where displayname <> '__invalid' ORDER BY displayname";
	$results = $db->getAll($sql, DB_FETCHMODE_ASSOC);
	if(DB::IsError($results)) {
		return array();
	}
	// Make array backward compatible, put first 4 columns as numeric
	$count = 0;
	foreach($results as $item) {
		if (!$compound && strstr($item['filename'],'&') !== false) {
			unset($results[$count]);
		} else {
			$results[$count][0] = $item['id'];
			$results[$count][1] = $item['displayname'];
			$results[$count][2] = $item['filename'];
			$results[$count][3] = $item['description'];
		}
		$count++;
	}
	return $results;
}

function recordings_get($id) {
	global $db;
        $sql = "SELECT * FROM recordings where id='$id'";
        $results = $db->getRow($sql, DB_FETCHMODE_ASSOC);
        if(DB::IsError($results)) {
                $results = null;
        }
	return $results;
}

function recordings_add($displayname, $filename, $description='') {
	global $db;
	global $recordings_astsnd_path;

	// Check to make sure we can actually read the file if it has an extension (if it doesn't, 
	// it was put here by system recordings, so we know it's there.
	if (preg_match("/\.(au|g723|g723sf|g726-\d\d|g729|gsm|h263|ilbc|mp3|ogg|pcm|[au]law|[au]l|mu|sln|raw|vox|WAV|wav|wav49)$/", $filename)) {
		if (!is_readable($recordings_astsnd_path.$filename)) {
			print "<p>Unable to add ".$recordings_astsnd_path.$filename." - Can not read file!</p>";
			return false;
		}
		$fname = preg_replace("/\.(au|g723|g723sf|g726-\d\d|g729|gsm|h263|ilbc|mp3|ogg|pcm|[au]law|[au]l|mu|sln|raw|vox|WAV|wav|wav49)$/", "", $filename);

	} else {
		$fname = $filename;
	}
	$description = ($description != '') ? $db->escapeSimple($description) : _("No long description available");
	$displayname = $db->escapeSimple($displayname);
	sql("INSERT INTO recordings (displayname, filename, description) VALUES ( '$displayname', '$fname', '$description')");

	return true;
	
}

function recordings_update($id, $rname, $descr, $_REQUEST, $fcode=0, $fcode_pass='') {
	global $db;

	// Update the descriptive fields
	$fcode_pass = preg_replace("/[^0-9*]/" ,"", trim($fcode_pass));
	$results = sql("UPDATE recordings SET displayname = '".$db->escapeSimple($rname)."', description = '".$db->escapeSimple($descr)."', fcode='$fcode', fcode_pass='".$fcode_pass."' WHERE id = '$id'");
	
	// Build the file list from _REQUEST
        $astsnd = isset($asterisk_conf['astvarlibdir'])?$asterisk_conf['astvarlibdir']:'/var/lib/asterisk';
        $astsnd .= "/sounds/";
	$recordings = Array();

	// Set the file names from the submitted page, sysrec[N]
	// We don't set if feature code was selected, we use what was already there
	// because the fields will have been disabled and won't be accessible in the
	// $_REQUEST array anyhow
	//
	if ($fcode != 1) {
		// delete the feature code if it existed
		//
		$fcc = new featurecode('recordings', 'edit-recording-'.$id);
		$fcc->delete();
		unset($fcc);	
		foreach ($_REQUEST as $key => $val) {
			$res = strpos($key, 'sysrec');
			if ($res !== false) {
				// strip out any relative paths, since this is coming from a URL
				str_replace('..','',$val);

				$recordings[substr($key,6)]=$val;
			}
		}

		// Stick the filename in the database
		recordings_set_file($id, implode('&', $recordings));
	} else {
		// Add the feature code if it is needed
		//
		$fcc = new featurecode('recordings', 'edit-recording-'.$id);
		$fcc->setDescription("Edit Recording: $rname");
		$fcc->setDefault('*29'.$id);
		$fcc->update();
		unset($fcc);	
	}

	// In _REQUEST there are also various actions (possibly) 
	// up[N] - Move file id N up one place
	// down[N] - Move fid N down one place
	// del[N] - Delete fid N
	
	foreach ($_REQUEST as $key => $val) {
		if (strpos($key,"_") == 0) {
	      		$up = strpos($key, "up");

			$down = strpos($key, "down");
			$del = strpos($key, "del");
		}
		if ( $up !== false ) {
			$up = substr($key, 2);
			recordings_move_file_up($id, $up);
		}
		if ($del !== false ) {
			$del = substr($key,3);
			recordings_delete_file($id, $del);
		}
		if ($down !== false ) {
			$down = substr($key,4);
			recordings_move_file_down($id, $down);
		}
	}
}

function recordings_move_file_up($id, $src) {
	$files = recordings_get_file($id);
	if ($src === 0 || $src < 0) { return false; } // Should never happen, up shouldn't appear whten fid=0
	$tmparr = explode('&', $files);
	$tmp = $tmparr[$src-1];
	$tmparr[$src-1] = $tmparr[$src];
	$tmparr[$src] = $tmp;
	recordings_set_file($id, implode('&', $tmparr));
}
function recordings_move_file_down($id, $src) {
	$files = recordings_get_file($id);
	$tmparr = explode('&', $files);
	$tmp = $tmparr[$src+1];
	$tmparr[$src+1] = $tmparr[$src];
	$tmparr[$src] = $tmp;
	recordings_set_file($id, implode('&', $tmparr));
}
function recordings_delete_file($id, $src) {
	$files = recordings_get_file($id);
	$tmparr = explode('&', $files);
	$tmp = Array();
	$counter = 0;
	foreach ($tmparr as $file) {
		if ($counter != $src) { $tmp[] = $file; }
		$counter++;
	}
	recordings_set_file($id, implode('&', $tmp));
}
	

function recordings_del($id) {
	$results = sql("DELETE FROM recordings WHERE id = \"$id\"");

	// delete the feature code if it existed
	$fcc = new featurecode('recordings', 'edit-recording-'.$id);
	$fcc->delete();
	unset($fcc);	
}

function recordings_set_file($id, $filename) {
	global $db;
	// Strip off any dangling &'s on the end:
	$filename = rtrim($filename, '&');
	$results = sql("UPDATE recordings SET filename = '".$db->escapeSimple($filename)."' WHERE id = '$id'");
}



function recordings_readdir($snddir) {
	$files = recordings_getdir($snddir);
	$ptr = 0;
	foreach ($files as $fnam) {
		$files[$ptr] = substr($fnam, strlen($snddir)+1);
		$ptr++;
	}
	// Strip off every possible file extension
	$flist = preg_replace("/\.(au|g723|g723sf|g726-\d\d|g729|gsm|h263|ilbc|mp3|ogg|pcm|[au]law|[au]l|mu|sln|raw|vox|WAV|wav|wav49)$/", "", $files);
	sort($flist);
	return array_unique($flist);
}
	
function recordings_getdir($snddir) {
	$dir = opendir($snddir);
	$files = Array();
	while ($fn = readdir($dir)) {
		if ($fn == '.' || $fn == '..') { continue; }
		if (is_dir($snddir.'/'.$fn)) {
			$files = array_merge(recordings_getdir($snddir.'/'.$fn), $files);
			continue;
		}
		$files[] = $snddir.'/'.$fn;
	}
	return $files;
}

function recordings_list_usage($id) {
	global $active_modules;
	$full_usage_arr = array();

	foreach(array_keys($active_modules) as $mod) {
		$function = $mod."_recordings_usage";
		if (function_exists($function)) {
			$recordings_usage = $function($id);
			if (!empty($recordings_usage)) {
				$full_usage_arr = array_merge($full_usage_arr, $recordings_usage);
			}
		}
	}
	return $full_usage_arr;
}

?>
