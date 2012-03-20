<?php

/**
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 * 
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@zpanelcp.com
 * @copyright (c) 2008-2011 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
 echo "<script src=\"http://code.jquery.com/jquery-latest.js\"></script>";
	set_time_limit(0);
	ini_set('memory_limit', '256M');
	include($_SERVER['DOCUMENT_ROOT'] . '/cnf/db.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/db/driver.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/debug/logger.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/runtime/dataobject.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/runtime/hook.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/sys/versions.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/ctrl/options.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/fs/director.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/dryden/fs/filehandler.class.php');
	include($_SERVER['DOCUMENT_ROOT'] . '/inc/dbc.inc.php');
	try {	
		$zdbh = new db_driver("mysql:host=localhost;dbname=" . $dbname . "", $user, $pass);
	} catch (PDOException $e) {
		exit();
	}
	if (isset($_POST['inDownLoad'])){
		$download = $_POST['inDownLoad'];
	} else {
		$download = 0;	
	}
	if (isset($_GET['id']) && $_GET['id'] != ""){
		$userid = $_GET['id'];
	    $rows = $zdbh->prepare("
	    	SELECT * FROM x_accounts 
	        LEFT JOIN x_profiles ON (x_accounts.ac_id_pk=x_profiles.ud_user_fk) 
	        LEFT JOIN x_groups   ON (x_accounts.ac_group_fk=x_groups.ug_id_pk) 
	        LEFT JOIN x_packages ON (x_accounts.ac_package_fk=x_packages.pk_id_pk) 
	        LEFT JOIN x_quotas   ON (x_accounts.ac_package_fk=x_quotas.qt_package_fk) 
	        WHERE x_accounts.ac_id_pk= " . $userid . "
	        ");
	    $rows->execute();
	    $dbvals = $rows->fetch();
		if ($backup = ExecuteBackup($userid, $dbvals['ac_user_vc'], $download)){
			echo "<p>Ready to download file: <b>" .basename($backup) . "<b></p>";
			echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" value=\"".$backup."\" id=\"getfile\" name=\"".$backup."\" onClick=\"return GetDownload();\">Download Now</button>";
			echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" value=\"Close Window\" onClick=\"return window.close()\">Close Window</button>";
		} else {
			echo "Could not find user!";
		}
	} else {
		$userid = NULL;
	}
	
	function test($userid, $username, $download = 0) {
		include($_SERVER['DOCUMENT_ROOT'] . '/cnf/db.php');
		try {	
			$zdbh = new db_driver("mysql:host=localhost;dbname=" . $dbname . "", $user, $pass);
		} catch (PDOException $e) {
			exit();
		}
		$homedir = ctrl_options::GetOption('hosted_dir') . $username;
		$backupname = $username . "_" . date("M-d-Y_hms", time());
        $dbstamp = date("dmy_Gi", time());
		
		echo $homedir . "<BR>";
		echo $backupname . "<BR>";
		echo $dbstamp = date("dmy_Gi", time()) . "<BR>";
		echo ctrl_options::GetOption('temp_dir') . "<BR>";
		echo fs_director::SlashesToWin(ctrl_options::GetOption('zip_exe') . " a -tzip -y-r " . ctrl_options::GetOption('temp_dir') . $backupname . ".zip " . $homedir . "/public_html") . "<BR>";
		echo $bkcommand = ctrl_options::GetOption('mysqldump_exe') . " -h " . $host . " -u " . $user . " -p" . $pass . " --no-create-db  > " . ctrl_options::GetOption('temp_dir') . "_" . $dbstamp . ".sql" . "<BR>";
		return true;
	}

	function ExecuteBackup($userid, $username, $download = 0) {
		include($_SERVER['DOCUMENT_ROOT'] . '/cnf/db.php');
		try {	
			$zdbh = new db_driver("mysql:host=localhost;dbname=" . $dbname . "", $user, $pass);
		} catch (PDOException $e) {
			exit();
		}
		if (!is_dir(ctrl_options::GetOption('temp_dir'))) {
        	fs_director::CreateDirectory(ctrl_options::GetOption('temp_dir'));
        }
        // Lets grab and archive the user's web data....
        $homedir = ctrl_options::GetOption('hosted_dir') . $username;
        $backupname = $username . "_" . date("M-d-Y_hms", time());
        $dbstamp = date("dmy_Gi", time());
        // We now see what the OS is before we work out what compression command to use..
        if (sys_versions::ShowOSPlatformVersion() == "Windows") {
            $resault = exec(fs_director::SlashesToWin(ctrl_options::GetOption('zip_exe') . " a -tzip -y-r " . ctrl_options::GetOption('temp_dir') . $backupname . ".zip " . $homedir . "/public_html"));
        } else {//cd /var/zpanel/hostdata/zadmin/; zip -r backups/backup.zip public_html/
            $resault = exec("cd ".$homedir ."/ && " . ctrl_options::GetOption('zip_exe') . " -r9 " . ctrl_options::GetOption('temp_dir') . $backupname . " public_html/*");
            @chmod(ctrl_options::GetOption('temp_dir') . $backupname . ".zip", 0777);
        }
        // Now lets backup all MySQL datbases for the user and add them to the archive...
        $sql = "SELECT COUNT(*) FROM x_mysql_databases WHERE my_acc_fk=" . $userid . " AND my_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_acc_fk=" . $userid . " AND my_deleted_ts IS NULL");
                $sql->execute();
                while ($row_mysql = $sql->fetch()) {
                    $bkcommand = ctrl_options::GetOption('mysqldump_exe') . " -h " . $host . " -u " . $user . " -p" . $pass . " --no-create-db " . $row_mysql['my_name_vc'] . " > " . ctrl_options::GetOption('temp_dir') . $row_mysql['my_name_vc'] . "_" . $dbstamp . ".sql";
                    passthru($bkcommand);
                    // Add it to the ZIP archive...
                    if (sys_versions::ShowOSPlatformVersion() == "Windows") {
                        $resault = exec(fs_director::SlashesToWin(ctrl_options::GetOption('zip_exe') . " u " . ctrl_options::GetOption('temp_dir') . $backupname . ".zip " . ctrl_options::GetOption('temp_dir') . $row_mysql['my_name_vc'] . "_" . $dbstamp . ".sql"));
                    } else {
                        $resault = exec("cd ".ctrl_options::GetOption('temp_dir') ."/ && " . ctrl_options::GetOption('zip_exe') . " " . ctrl_options::GetOption('temp_dir') . $backupname . "  " .$row_mysql['my_name_vc'] . "_" . $dbstamp . ".sql");
                    }
                    unlink(ctrl_options::GetOption('temp_dir') . $row_mysql['my_name_vc'] . "_" . $dbstamp . ".sql");
                }
            }
        }
        // We have the backup now lets output it to disk or download
        if (file_exists(ctrl_options::GetOption('temp_dir') . $backupname . ".zip")) {

            // If Disk based backups are allowed in backup config
            if (strtolower(ctrl_options::GetOption('disk_bu')) == "true") {
                // Copy Backup to user home directory...
                $backupdir = $homedir . "/backups/";
                if (!is_dir($backupdir)) {
                    fs_director::CreateDirectory($backupdir);
                }
                copy(ctrl_options::GetOption('temp_dir') . $backupname . ".zip", $backupdir . $backupname . ".zip");
                fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0777);
            } else {
                $backupdir = ctrl_options::GetOption('temp_dir');
            }

            // If Client has checked to download file
            if ($download <> 0) {
			/*
                if (sys_versions::ShowOSPlatformVersion() == "Windows") {
                    # Now we send the output (Windows)...
                    header('Pragma: public');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header('Cache-Control: private', false);
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename=' . $backupname . '.zip');
                    header('Content-Transfer-Encoding: binary');
                    header('Content-Length: ' . filesize($backupdir . $backupname . '.zip ') . '');
                    readfile($backupdir . $backupname . ".zip ");
                } else {
				
                    # Now we send the output (POSIX)...
                    $file = $backupdir . $backupname . ".zip";
                    header('Pragma: public');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header('Cache-Control: private', false);
                    header('Content-Description: File Transfer');
                    header('Content-Transfer-Encoding: binary');
                    header('Content-Type: application/force-download');
                    header('Content-Length: ' . filesize($file));
                    header('Content-Disposition: attachment; filename=' . $backupname . '.zip');
                    readfile_chunked($file);
                }
				*/
				return ctrl_options::GetOption('temp_dir') . $backupname . ".zip";
            }
           unlink(ctrl_options::GetOption('temp_dir') . $backupname . ".zip");
        } else {
            //self::$filenotexist = true;
        }
		return TRUE;
    }
	
    function readfile_chunked($filename) {
        $chunksize = 1 * (1024 * 1024);
        $buffer = '';
        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        while (!feof($handle)) {
            $buffer = fread($handle, $chunksize);
            print $buffer;
        }
        return fclose($handle);
    }

?>