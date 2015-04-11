<?php
/*
daemon.inc.php, holds the WiFiDB daemon functions.
Copyright (C) 2011 Phil Ferland

This program is free software; you can redistribute it and/or modify it under the terms
of the GNU General Public License as published by the Free Software Foundation; either
version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details.

ou should have received a copy of the GNU General Public License along with this program;
if not, write to the

   Free Software Foundation, Inc.,
   59 Temple Place, Suite 330,
   Boston, MA 02111-1307 USA
*/

class daemon extends wdbcli
{
	public function __construct($config, $daemon_config)
	{
		parent::__construct($config, $daemon_config);
		$this->time_interval_to_check 	=	$daemon_config['time_interval_to_check'];
		$this->default_user		 		=	$daemon_config['default_user'];
		$this->default_title			=	$daemon_config['default_title'];
		$this->default_notes			=	$daemon_config['default_notes'];
		$this->StatusWaiting			=	$daemon_config['status_waiting'];
		$this->StatusRunning			=	$daemon_config['status_running'];
		$this->node_name 				= 	$daemon_config['wifidb_nodename'];
        $this->NumberOfThreads          =   $daemon_config['NumberOfThreads'];
		$this->daemon_name				=	"";
		$this->job_interval				=	0;
		$this->ForceDaemonRun			=   0;
		$this->ImportID					=	0;
		$this->DeleteDeadPids			=	$daemon_config['DeleteDeadPids'];
		$this->convert_extentions   = array('csv','db','db3','vsz');

		$this->daemon_version			=	"3.0";
		$this->ver_array['Daemon']  = array(
									"last_edit"				=>	"2015-Mar-21",
									"CheckDaemonKill"		=>	"1.0",#
									"cleanBadImport"		=>	"1.0",
									"GenerateUserImport"	=>	"1.0",
									"insert_file"			=>	"1.0",
									"parseArgs"				=>	"1.0"
									);
	}
####################
	/**
	 * @return int
	 */
	public function CheckDaemonKill()
	{
		$D_SQL = "SELECT `daemon_state` FROM `wifi`.`settings` WHERE `node_name` = ? LIMIT 1";
		$Dresult = $this->sql->conn->prepare($D_SQL);
		$Dresult->bindParam(1, $this->node_name, PDO::PARAM_STR);
		$Dresult->execute();
		$this->sql->checkError(__LINE__, __FILE__);
		$daemon_state = $Dresult->fetch();
		if($daemon_state['daemon_state'] == 0)
		{
			unlink($this->pid_file);
			$this->exit_msg = "Daemon was told to kill itself";
			return 1;
		}else
		{
			$this->exit_msg = NULL;
			return 0;
		}
	}


	function cleanBadImport($user_import_id = 0, $file_id = 0, $file_tmp_id = 0, $file_importing_id = 0, $error_msg = "")
	{
		$sql = "INSERT INTO `wifi`.`files_bad` (`file`,`user`,`notes`,`title`,`size`,`date`,`hash`,`converted`,`prev_ext`,`error_msg`) SELECT `file`,`user`,`notes`,`title`,`size`,`date`,`hash`,`converted`,`prev_ext`,? FROM `wifi`.`files_tmp` WHERE `id` = ?";
		$prep = $this->sql->conn->prepare($sql);
		$prep->bindParam(1, $error_msg, PDO::PARAM_STR);
		$prep->bindParam(2, $file_tmp_id, PDO::PARAM_INT);
		$prep->execute();
		if($this->sql->checkError())
		{
			$this->verbosed("Failed to add bad file to bad import table.".var_export($this->sql->conn->errorInfo(),1), -1);
			$this->logd("Failed to add bad file to bad import table.".var_export($this->sql->conn->errorInfo(),1));
			throw new ErrorException("Failed to add bad file to bad import table.");
		}else
		{
			$this->verbosed("Added file to the Bad Import table.");
		}
		$thread_row_id = $this->sql->conn->lastInsertId();
		$sql = "UPDATE `wifi`.`files_bad` SET `thread_id` = ?, `node_name` = ? WHERE `id` = ?";
		$prep = $this->sql->conn->prepare($sql);
		$prep->bindParam(1, $this->thread_id, PDO::PARAM_INT);
		$prep->bindParam(2, $this->node_name, PDO::PARAM_STR);
		$prep->bindParam(3, $thread_row_id, PDO::PARAM_INT);
		$prep->execute();

		if($this->sql->checkError())
		{
			$this->verbosed("Failed to update bad file with the Thread ID.".var_export($this->sql->conn->errorInfo(),1), -1);
			$this->logd("Failed to update bad file with the Thread ID.".var_export($this->sql->conn->errorInfo(),1));
			throw new ErrorException("Failed to update bad file with the Thread ID.");
		}else
		{
			$this->verbosed("Updated file Thread ID in the Bad Import table.");
		}

		if($user_import_id !== 0)
		{
			if(is_array($user_import_id))
			{
				foreach($user_import_id as $import_id)
				{
					$this->RemoveUserImport($import_id);
				}
			}elseif($user_import_id === 0){}
			else
			{
				$this->RemoveUserImport($user_import_id);
			}
		}

		if($file_importing_id !== 0)
		{
			$sql = "DELETE FROM `wifi`.`files_importing` WHERE `id` = ?";
			$prep = $this->sql->conn->prepare($sql);
			$prep->bindParam(1, $file_importing_id, PDO::PARAM_INT);
			$prep->execute();
			if($this->sql->checkError())
			{
				$this->verbosed("Failed to remove file from the files_importing table.".var_export($this->sql->conn->errorInfo(),1), -1);
				$this->logd("Failed to remove bad file from the files_importing table.".var_export($this->sql->conn->errorInfo(),1));
				throw new ErrorException("Failed to remove bad file from the files_importing table.");
			}else
			{
				$this->verbosed("Cleaned file from the files_importing table.");
			}
		}

		if($file_id !== 0)
		{
			$sql = "DELETE FROM `wifi`.`files` WHERE `id` = ?";
			$prep = $this->sql->conn->prepare($sql);
			$prep->bindParam(1, $file_id, PDO::PARAM_INT);
			$prep->execute();
			if($this->sql->checkError())
			{
				$this->verbosed("Failed to remove bad file from the files table.".var_export($this->sql->conn->errorInfo(),1), -1);
				$this->logd("Failed to remove bad file from the files table.".var_export($this->sql->conn->errorInfo(),1));
				throw new ErrorException("Failed to remove bad file from the files table.");
			}else
			{
				$this->verbosed("Cleaned file from the files table.");
			}
		}

		$sql = "DELETE FROM `wifi`.`files_tmp` WHERE `id` = ?";
		$prep = $this->sql->conn->prepare($sql);
		$prep->bindParam(1, $file_tmp_id, PDO::PARAM_INT);
		$prep->execute();
		if($this->sql->checkError())
		{
			$this->verbosed("Failed to remove bad file from the files tmp table.".var_export($this->sql->conn->errorInfo(),1), -1);
			$this->logd("Failed to remove bad file from the files tmp table.".var_export($this->sql->conn->errorInfo(),1));
			throw new ErrorException("Failed to remove bad file from the files tmp table.");
		}else
		{
			$this->verbosed("Cleaned file from the files tmp table.");
		}
	}

    /**
     * @param string $user
     * @param string $notes
     * @param string $title
     * @param string $hash
     * @return array
     * @throws ErrorException
     */
    function GenerateUserImportIDs($user = "", $notes = "", $title = "", $hash = "", $file_row = 0)
    {
        if($file_row === 0)
        {
            throw new ErrorException("GenerateUserImportIDs was passed a blank file_row, this is a fatal exception.");
        }

        if($user === "")
        {
            throw new ErrorException("GenerateUserImportIDs was passed a blank username, this is a fatal exception.");
        }
        $multi_user = explode("|", $user);
        $rows = array();
        $n = 0;
        # Now lets insert some preliminary data into the User Import table as a place holder for the finished product.
        $sql = "INSERT INTO `wifi`.`user_imports` ( `id` , `username` , `notes` , `title`, `hash`, `file_id`) VALUES ( NULL, ?, ?, ?, ?, ?)";
        $prep = $this->sql->conn->prepare($sql);
        foreach($multi_user as $muser)
        {
            if ($muser === ""){continue;}
            $prep->bindParam(1, $muser, PDO::PARAM_STR);
            $prep->bindParam(2, $notes, PDO::PARAM_STR);
            $prep->bindParam(3, $title, PDO::PARAM_STR);
            $prep->bindParam(4, $hash, PDO::PARAM_STR);
            $prep->bindParam(5, $file_row, PDO::PARAM_INT);
            $prep->execute();

            if($this->sql->checkError())
            {
                $this->logd("Failed to insert Preliminary user information into the Imports table. :(", "Error");
                $this->verbosed("Failed to insert Preliminary user information into the Imports table. :(\r\n".var_export($this->sql->conn->errorInfo(), 1), -1);
                Throw new ErrorException;
            }
            $n++;
            $rows[$n] = $this->sql->conn->lastInsertId();
            $this->logd("User ($muser) import row: ".$this->sql->conn->lastInsertId());
            $this->verbosed("User ($muser) import row: ".$this->sql->conn->lastInsertId());
        }
        return $rows;
    }

	/**
	 * @param $file
	 * @param $file_names
	 * @return int
	 * @throws ErrorException
	 */
	public function insert_file($file, $file_names)
	{
		$source = $this->PATH.'import/up/'.$file;
		#echo $source."\r\n";
		$hash = hash_file('md5', $source);
		$size1 = $this->format_size(filesize($source));
		if(@is_array($file_names[$hash]))
		{
			$user	=	$file_names[$hash]['user'];
			$title	=	$file_names[$hash]['title'];
			$notes	=	$file_names[$hash]['notes'];
			$date	=	$file_names[$hash]['date'];
			$hash_	=	$file_names[$hash]['hash'];
			#echo "Is in filenames.txt\n";
		}else
		{
			$user	=	$this->default_user;
			$title	=	$this->default_title;
			$notes	=	$this->default_notes;
			$date	=	date("y-m-d H:i:s");
			$hash_	=	$hash;
			#echo "Recovery import, no previous data :(\n";

		}
		$this->logd("=== Start Daemon Prep of ".$file." ===");

		$sql = "INSERT INTO `wifi`.`files_tmp` ( `id`, `file`, `date`, `user`, `notes`, `title`, `size`, `hash`  )
																VALUES ( '', '$file', '$date', '$user', '$notes', '$title', '$size1', '$hash_')";
		$prep = $this->sql->conn->prepare($sql);
		$prep->bindParam(1, $file, PDO::PARAM_STR);
		$prep->bindParam(2, $date, PDO::PARAM_STR);
		$prep->bindParam(3, $user, PDO::PARAM_STR);
		$prep->bindParam(4, $notes, PDO::PARAM_STR);
		$prep->bindParam(5, $title, PDO::PARAM_STR);
		$prep->bindParam(6, $size1, PDO::PARAM_STR);
		$prep->bindParam(7, $hash, PDO::PARAM_STR);
		$prep->execute();

		$err = $this->sql->conn->errorInfo();
		if($err[0] == "00000")
		{
			#$this->verbosed("File Inserted into Files_tmp. ({$file})\r\n");
			$this->logd("File Inserted into Files_tmp.".$sql);
			return 1;
		}else
		{
			#$this->verbosed("Failed to insert file info into Files_tmp.\r\n".var_export($this->sql->conn->errorInfo(),1));
			$this->logd("Failed to insert file info into Files_tmp.".var_export($this->sql->conn->errorInfo(),1));
			throw new ErrorException("Failed to insert file info into Files_tmp.".var_export($this->sql->conn->errorInfo()) );
		}
	}

	public function SetNextJob($job_id)
	{
		$nextrun = date("Y-m-d G:i:s", strtotime("+".$this->job_interval." minutes"));
		$this->verbosed("Setting Job Next Run to ".$nextrun, 1);

		$sql = "UPDATE `wifi`.`schedule` SET `nextrun` = ? , `status` = ? WHERE `id` = ?";
		$prepnr = $this->sql->conn->prepare($sql);
		$prepnr->bindParam(1, $nextrun, PDO::PARAM_STR);
		$prepnr->bindParam(2, $this->StatusWaiting, PDO::PARAM_STR);
		$prepnr->bindParam(3, $job_id, PDO::PARAM_INT);

		$prepnr->execute();
		$this->sql->checkError(__LINE__, __FILE__);
	}

	public function SetStartJob($job_id)
	{
		$nextrun = date("Y-m-d G:i:s", strtotime("+".$this->job_interval." minutes"))."";
		$this->verbosed("Starting - Job:".$this->daemon_name." Id:".$job_id, 1);

		$sql = "UPDATE `wifi`.`schedule` SET `status` = ?, `nextrun` = ? WHERE `id` = ?";
		$prepsr = $this->sql->conn->prepare($sql);
		$prepsr->bindParam(1, $this->StatusRunning, PDO::PARAM_STR);
		$prepsr->bindParam(2, $nextrun, PDO::PARAM_STR);
		$prepsr->bindParam(3, $job_id, PDO::PARAM_INT);

		$prepsr->execute();
		$this->sql->checkError(__LINE__, __FILE__);
	}

    public function SpawnImportDaemon($ThreadID = 0, $force_flag)
    {
        # Copy data from the files_tmp table to the files_importing table.
        $daemon_sql = "INSERT INTO wifi.files_importing (`file`, `user`, `title`, `notes`, `size`, `date`, `hash`, `tmp_id`) SELECT `file`, `user`, `title`, `notes`, `size`, `date`, `hash`, `id` FROM `wifi`.`files_tmp` WHERE importing = 0 ORDER BY `id` ASC LIMIT 1;";
        $this->sql->conn->query($daemon_sql);
        $LastInsert = $this->sql->conn->lastInsertId();
        if($LastInsert > 0)
        {
            # Select the data that was just inserted.
            $sql = "SELECT `file`, `user`, `title`, `notes`, `size`, `date`, `hash`, `tmp_id` FROM wifi.files_importing WHERE `id` = $LastInsert";
            $result = $this->sql->conn->query($sql);
            $fetch = $result->fetch(2);

            # Delete the files_tmp row.
            $sql = "DELETE FROM wifi.files_tmp WHERE id = ?";
            $deleteResult = $this->sql->conn->prepare($sql);
            $deleteResult->bindParam(1, $fetch['tmp_id'], PDO::PARAM_INT);
            $deleteResult->execute();
			$this->ImportID = $LastInsert;
			#$this->ImportID = $LastInsert;
            if($force_flag)
			{
				$force_flag_arg = "-f";
			}else
			{
				$force_flag_arg = "";
			}
			var_dump($this->ImportID);
			sleep(rand(3, 20));
			#exec("php ./import_process.php $force_flag_arg -t=$ThreadID -i=$LastInsert" , $out , $ret);
			exit(1);
        }
        return -1;
    }

    public function  RemoveUserImport($import_ID = 0)
    {
        $sql = "DELETE FROM `wifi`.`user_imports` WHERE `id` = ?";
        $prep = $this->sql->conn->prepare($sql);
        $prep->bindParam(1, $import_ID, PDO::PARAM_STR);
        $prep->execute();
        if($this->sql->checkError())
        {
            $this->verbosed("Failed to remove bad file from the user import table.".var_export($this->sql->conn->errorInfo(),1), -1);
            $this->logd("Failed to remove bad file from the user import table.".var_export($this->sql->conn->errorInfo(),1));
            throw new ErrorException("Failed to remove bad file from the user import table.");
        }else
        {
            $this->verbosed("Cleaned file from the User Import table.");
        }
        return 1;
    }


	public function ImportProcess()
	{
		$this->verbosed("Running...");
		#Check if there are any imports
		if($this->checkDaemonKill())# Safely kill script if Daemon kill flag has been set
		{
			$this->verbosed("The flag to kill the daemon is set. unset it to run this daemon.");
			if(!$this->ForceDaemonRun){$this->SetNextJob($this->job_id);}
			#unlink($this->pid_file);
			exit($this->exit_msg);
		}

		$daemon_sql = "SELECT `id`, `file`, `user`, `notes`, `title`, `date`, `size`, `hash` FROM `wifi`.`files_importing` where `id` = ?";
		$result = $this->sql->conn->prepare($daemon_sql);
		$result->bindParam(1, $this->ImportID, PDO::PARAM_INT);
		$result->execute();

		if($this->sql->checkError(__LINE__, __FILE__))
		{
			$this->verbosed("There was an error getting a list of import files");
			exit("Error getting Import file info.");
		}
		elseif($result->rowCount() === 0)
		{
			$this->verbosed("There are no imports waiting, go import something and funny stuff will happen.");
			exit("No Imports waiting....");
		}
		else
		{
			##### make sure import/export files are in sync with remote nodes
			//$this->verbosed("Synchronizing files between nodes...", 1);
			//$cmd = '/opt/unison/sync_wifidb_imports > /opt/unison/log/sync_wifidb_imports 2>&1';
			//exec ($cmd);
			#####

			$file_to_Import = $result->fetch(2);
			if(!@$file_to_Import['id'])
			{
				$this->verbosed("Error fetching data.... Skipping row for admin to check into it.");
			}else
			{
				$remove_file = $file_to_Import['id'];
				$source = $this->PATH.'import/up/'.$file_to_Import['file'];

				#trigger_error($file_to_Import['file']."\r\n".$source."\r\n", E_USER_NOTICE);
				$file_src = explode(".",$file_to_Import['file']);
				$file_type = strtolower($file_src[1]);
				$file_name = $file_to_Import['file'];
				$file_hash = $file_to_Import['hash'];
				$file_size = (filesize($source)/1024);
				$file_date = $file_to_Import['date'];
				#Lets check and see if it is has a valid VS1 file header.
				if(in_array($file_type, $this->convert_extentions))
				{
					$this->verbosed("This file needs to be converted to VS1 first. Please wait while the computer does the work for you.", 1);
					$update_tmp = "UPDATE `wifi`.`files_tmp` SET `importing` = '0', `ap` = '@#@# CONVERTING TO VS1 @#@#', `converted` = '1', `prev_ext` = ? WHERE `id` = ?";
					$prep = $this->sql->conn->prepare($update_tmp);
					$prep->bindParam(1, $file_type, PDO::PARAM_STR);
					$prep->bindParam(2, $remove_file, PDO::PARAM_INT);
					$prep->execute();
					$err = $this->sql->conn->errorCode();
					if($err[0] != "00000")
					{
						$this->verbosed("Failed to set the Import flag for this file. If running with more than one Import Daemon you may have problems.", -1);
						$this->logd("Failed to set the Import flag for this file. If running with more than one Import Daemon you may have problems.".var_export($daemon->sql->conn->errorInfo(),1), "Error", $daemon->This_is_me);
						throw new ErrorException("Failed to set the Import flag for this file. If running with more than one Import Daemon you may have problems.".var_export($daemon->sql->conn->errorInfo(),1));
					}
					$ret_file_name = $this->convert->main($source);
					if($ret_file_name === -1)
					{
						$this->verbosed("Error Converting File. $source, Skipping to next file.");
						exit("Error Converting File. $source, Skipping to next file.");
					}

					$parts = pathinfo($ret_file_name);
					$dest_name = $parts['basename'];
					$file_hash1 = hash_file('md5', $ret_file_name);
					$file_size1 = (filesize($ret_file_name)/1024);

					$update = "UPDATE `wifi`.`files_tmp` SET `file` = ?, `hash` = ?, `size` = ? WHERE `id` = ?";
					$prep = $this->sql->conn->prepare($update);
					$prep->bindParam(1, $dest_name, PDO::PARAM_STR);
					$prep->bindParam(2, $file_hash1, PDO::PARAM_STR);
					$prep->bindParam(3, $file_size1, PDO::PARAM_STR);
					$prep->bindParam(4, $remove_file, PDO::PARAM_INT);
					$prep->execute();
					$err = $this->sql->conn->errorCode();
					if($err[0] == "00000")
					{
						$this->verbosed("Conversion completed.", 1);
						$this->logd("Conversion completed.".$file_src[0].".".$file_src[1]." -> ".$dest_name, $this->This_is_me);
						$source = $ret_file_name;
						$file_name = $dest_name;
						$file_hash = $file_hash1;
						$file_size = $file_size1;
					}else
					{
						$this->verbosed("Conversion completed, but the update of the table with the new info failed.", -1);
						$this->logd("Conversion completed, but the update of the table with the new info failed.".$file_src[0].".".$file_src[1]." -> ".$source.var_export($daemon->sql->conn->errorInfo(),1), "Error", $daemon->This_is_me);
						throw new ErrorException("Conversion completed, but the update of the table with the new info failed.".$file_src[0].".".$file_src[1]." -> ".$source.var_export($daemon->sql->conn->errorInfo(),1));
					}
				}
				$return	=	file($source);
				$count	=	count($return);
				if(!($count <= 8) && preg_match("/Vistumbler VS1/", $return[0]))//make sure there is at least a 'valid' file in the field
				{
					$this->verbosed("Hey look! a valid file waiting to be imported, lets import it.", 1);
					$update_tmp = "UPDATE `wifi`.`files_tmp` SET `importing` = '1', `ap` = 'Preparing for Import' WHERE `id` = ?";
					$prep4 = $this->sql->conn->prepare($update_tmp);
					$prep4->bindParam(1, $remove_file, PDO::PARAM_INT);
					$prep4->execute();
					if($this->sql->checkError(__LINE__, __FILE__))
					{
						$this->verbosed("Failed to set the Import flag for this file. If running with more than one Import Daemon you may have problems.",
							-1);
						$this->logd("Failed to set the Import flag for this file. If running with more than one Import Daemon you may have problems.".var_export($this->sql->conn->errorInfo(),1),
							"Error", $this->This_is_me);
						Throw new ErrorException("Failed to set the Import flag for this file. If running with more than one Import Daemon you may have problems.");
					}

					//check to see if this file has already been imported into the DB
					$sql_check = "SELECT `hash` FROM `wifi`.`files` WHERE `hash` = ? LIMIT 1";
					$prep = $this->sql->conn->prepare($sql_check);
					$prep->bindParam(1, $file_hash, PDO::PARAM_STR);
					$prep->execute();
					if($this->sql->checkError(__LINE__, __FILE__))
					{
						$this->logd("Failed to select file hash from files table. :(",
							"Error", $this->This_is_me);
						$this->verbosed("Failed to select file hash from files table. :(\r\n".var_export($this->sql->conn->errorInfo(), 1), -1);
						Throw new ErrorException("Failed to select file hash from files table. :(");
					}

					$fileqq = $prep->fetch(2);

					if($file_hash !== @$fileqq['hash'])
					{
						if(count(explode(";", $file_to_Import['notes'])) === 1)
						{
							$user = str_replace(";", "", $file_to_Import['user']);
							$this->verbosed("Start Import of : (".$file_to_Import['id'].") ".$file_name, 1);
						}else
						{
							$user = $file_to_Import['user'];
							$this->verbosed("Start Import of : (".$file_to_Import['id'].") ".$file_name, 1);
						}
						$sql_select_tmp_file_ext = "SELECT `converted`, `prev_ext` FROM `wifi`.`files_tmp` WHERE `hash` = ?";
						$prep_ext = $this->sql->conn->prepare($sql_select_tmp_file_ext);
						$prep_ext->bindParam(1, $file_hash, PDO::PARAM_STR);
						$prep_ext->execute();
						if($this->sql->checkError())
						{
							$this->logd("Failed to select previous convert extension. :(",
								"Error", $this->This_is_me);
							$this->verbosed("Failed to select previous convert extension. :(\r\n".var_export($this->sql->conn->errorInfo(), 1), -1);
							Throw new ErrorException("Failed to select previous convert extension. :(");
						}
						$prev_ext = $prep_ext->fetch(2);
						$notes = $file_to_Import['notes'];
						$title = $file_to_Import['title'];

						$sql_insert_file = "INSERT INTO `wifi`.`files`
						(`id`, `file`, `date`, `size`, `aps`, `gps`, `hash`, `user`, `notes`, `title`, `converted`, `prev_ext`, `node_name`)
						VALUES (NULL, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)";
						$prep1 = $this->sql->conn->prepare($sql_insert_file);
						$prep1->bindParam(1, $file_name, PDO::PARAM_STR);
						$prep1->bindParam(2, $file_date, PDO::PARAM_STR);
						$prep1->bindParam(3, $file_size, PDO::PARAM_STR);
						$prep1->bindParam(4, $file_hash, PDO::PARAM_STR);
						$prep1->bindParam(5, $user, PDO::PARAM_STR);
						$prep1->bindParam(6, $notes, PDO::PARAM_STR);
						$prep1->bindParam(7, $title, PDO::PARAM_STR);
						$prep1->bindParam(8, $prev_ext['converted'], PDO::PARAM_INT);
						$prep1->bindParam(9, $prev_ext['prev_ext'], PDO::PARAM_STR);
						$prep1->bindParam(10, $this->node_name, PDO::PARAM_STR);
						$prep1->execute();

						if($this->sql->checkError(__LINE__, __FILE__))
						{
							$this->logd("Failed to Insert the results of the new Import into the files table. :(",
								"Error", $this->This_is_me);
							$this->verbosed("Failed to Insert the results of the new Import into the files table. :(\r\n".var_export($this->sql->conn->errorInfo(), 1), -1);
							Throw new ErrorException("Failed to Insert the results of the new Import into the files table. :(");
						}else{
							$file_row = $this->sql->conn->lastInsertID();
							#var_dump($file_row);
							$this->verbosed("Added $source ($remove_file) to the Files table.\n");
						}

						$import_ids = $this->GenerateUserImportIDs($user, $notes, $title, $file_hash, $file_row);

						#Import the VS1 File into the database, well at least attempt to do it...
						$tmp = $this->import->import_vs1( $source, $user, $file_row, $this->ImportID );

						if(@$tmp[0] === -1)
						{
							trigger_error("Import Error! Reason: $tmp[1] |=| $source Thread ID: ".$this->thread_id, E_USER_NOTICE);
							$this->logd("Skipping Import \nReason: $tmp[1]\n".$file_name,
								"Error", $this->This_is_me);
							$this->verbosed("Skipping Import \nReason: $tmp[1]\n".$file_name, -1);
							//remove files_tmp row and user_imports row
							$this->cleanBadImport($import_ids, $file_row, $remove_file, "Import Error! Reason: $tmp[1] |=| $source", $this->thread_id);
						}else
						{
							$this->verbosed("Finished Import of :".$file_name." | AP Count:".$tmp['aps']." - GPS Count: ".$tmp['gps'], 3);
							$update_files_table_sql = "UPDATE `wifi`.`files` SET `aps` = ?, `gps` = ?, `completed` = 1 WHERE `id` = ?";
							$prep_update_files_table = $this->sql->conn->prepare($update_files_table_sql);
							$prep_update_files_table->bindParam(1, $tmp['aps'], PDO::PARAM_STR);
							$prep_update_files_table->bindParam(2, $tmp['gps'], PDO::PARAM_STR);
							$prep_update_files_table->bindParam(3, $file_row, PDO::PARAM_INT);

							$prep_update_files_table->execute();
							$this->sql->checkError(__LINE__, __FILE__);

							$sql = "UPDATE `wifi`.`user_imports` SET `points` = ?, `date` = ?, `aps` = ?, `gps` = ?, `file_id` = ?, `converted` = ?, `prev_ext` = ? WHERE `id` = ?";
							$prep3 = $this->sql->conn->prepare($sql);
							foreach($import_ids as $id)
							{
								$prep3->bindParam(1, $tmp['imported'], PDO::PARAM_STR);
								$prep3->bindParam(2, $file_date, PDO::PARAM_STR);
								$prep3->bindParam(3, $tmp['aps'], PDO::PARAM_INT);
								$prep3->bindParam(4, $tmp['gps'], PDO::PARAM_INT);
								$prep3->bindParam(5, $file_row, PDO::PARAM_INT);
								$prep3->bindParam(6, $prev_ext['converted'], PDO::PARAM_INT);
								$prep3->bindParam(7, $prev_ext['prev_ext'], PDO::PARAM_STR);
								$prep3->bindParam(8, $id, PDO::PARAM_INT);
								$prep3->execute();
								$this->sql->checkError(__LINE__, __FILE__);
								$this->verbosed("Updated User Import row. ($id : $file_hash)", 2);
							}
						}
					}else
					{
						trigger_error("File already imported. $source Thread ID: ".$this->thread_id, E_USER_NOTICE);
						$this->logd("File has already been successfully imported into the Database, skipping.\r\n\t\t\t$source ($remove_file)",
							"Warning", $this->This_is_me);
						//$this->verbosed("File has already been successfully imported into the Database. Skipping and deleting source file.\r\n\t\t\t$source ($remove_file)");
						//unlink($source);
						$this->verbosed("File has already been successfully imported into the Database. Skipping source file.\r\n\t\t\t$source ($remove_file)");
						$this->cleanBadImport(0, 0, $remove_file, 'Already Imported', $this->thread_id);
					}
				}else
				{
					trigger_error("File is Empty or bad $source Thread ID: ".$this->thread_id, E_USER_NOTICE);
					$this->logd("File is empty or not valid. $source ($remove_file)",
						"Warning", $this->This_is_me);
					$this->verbosed("File is empty. Skipping and deleting from files_tmp. $source ($remove_file)\n");
					//unlink($source);
					$this->verbosed("File is empty, go and import something. Skipping source file. $source ($remove_file-$file_hash)\n");
					$this->cleanBadImport(0, 0, $remove_file, 'Empty or not valid', $this->thread_id);
				}
			}
		}
	}
#END DAEMON CLASS
}