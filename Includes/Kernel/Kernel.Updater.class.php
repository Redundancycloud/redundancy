<?php
	/**
	* Kernel.Updater.class.php
	*/	
	namespace Redundancy\Kernel;
	/**
	* This file containts methods to update the system
	* @license
	*
	* This program is free software; you can redistribute it and/or
	* modify it under the terms of the GNU General Public License as
	* published by the Free Software Foundation; either version 3 of
	* the License, or (at your option) any later version.
	*
	* This program is distributed in the hope that it will be useful, but
	* WITHOUT ANY WARRANTY; without even the implied warranty of
	* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
	* General Public License for more details at
	* http://www.gnu.org/copyleft/gpl.html
	* 
	* @author  squarerootfury <me@0fury.de>	
	* 
	*/
	class UpdateKernel{
		private $pattern = "/(?<major>\d+).(?<minor>\d+).(?<patch>\d+)+-(?<branch>[^-]+)-(?<stage>[^-]+)-(?<update>\d+)/";
		private $updateSource = "https://raw.githubusercontent.com/squarerootfury/redundancy/%s/Includes/Kernel/Kernel.Program.class.php";
		private $updatePackage = "https://github.com/squarerootfury/redundancy/archive/%s.zip";
		/**
		* Compares to verision structures and returns, if an update is needed
		* @param array $remoteVersion the remote version
		* @param array $localVersion the local version
		* @return bool
		*/
		public function IsUpdateAvailable($remoteVersion,$localVersion){			
			if ($remoteVersion == "" || $localVersion == "")
				return false;
			if ($remoteVersion["major"] > $localVersion["major"])
				return true;
			if ($remoteVersion["minor"] > $localVersion["minor"])
				return true;
			if ($remoteVersion["patch"] > $localVersion["patch"])
				return true;
			if ($remoteVersion["update"] > $localVersion["update"])
				return true;
			return false;
		}
		/*
		* Check if an update is needed
		* @return bool if an update is needed
		*/
		public function IsUpdateNeeded(){
			$remoteVersion = $this->GetLatestVersion();
			$localVersion = $this->GetVersion();
			return $this->IsUpdateAvailable($remoteVersion,$localVersion);
		}
		/**
		* Update the system update
		* @param string $token an administrative session token
		* @return bool
		*/
		public function Update($token){
			exit;
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;

			//
			$url = sprintf($this->updatePackage,$this->GetBranch());
			$branch = $this->GetBranch();
			$targetPath = $GLOBALS["Kernel"]->FileSystemKernel->GetSystemDir(\Redundancy\Classes\SystemDirectories::Temp);
			$extractPath = $GLOBALS["Kernel"]->FileSystemKernel->GetSystemDir(\Redundancy\Classes\SystemDirectories::Temp)."update";
			$updateTargetPath = $GLOBALS["Kernel"]->FileSystemKernel->GetSystemDir(\Redundancy\Classes\SystemDirectories::Temp);
			$result =  file_put_contents($targetPath."update.zip", fopen($url,'r'));

			$zip = new \ZipArchive;
			$entries = array();
			//Ignore following files which are not needed for the retail-system
			$ignoredItems = array("redundancy-".$branch."/Storage/",
				"redundancy-".$branch."/Temp/",
				"redundancy-".$branch."/Snapshots/",
				"redundancy-".$branch."/Includes/Kernel/Kernel.Config.class.php",
				"redundancy-".$branch."/webclient/",
				"redundancy-".$branch."/runTests.xml",
				"redundancy-".$branch."/test",
				"redundancy-".$branch."/tests",
				"redundancy-".$branch."/.travis.yml",	
				"redundancy-".$branch."/Language/.htaccess",
				"redundancy-".$branch."/System.log",
				"redundancy-".$branch."/README.md",
				"redundancy-".$branch."/install.php"				
				);
			if ($zip->open($targetPath."update.zip") === true) {
				for($i = 0; $i < $zip->numFiles; $i++) { 
					$entry = $zip->getNameIndex($i);
					$itemBlacklisted = false;
					foreach ($ignoredItems as $key => $value) {
						if ($entry == $value || strpos($entry, $value) === 0){ 
							$itemBlacklisted = true;
							//error_log("File $entry blacklisted!");
							break;
						}
					}			
					if (!$itemBlacklisted)
						$entries[] = $entry;			
				}  
				$zip->ExtractTo($extractPath,$entries);
				$zip->close();
			}
			//Copy the update contents to the root dir
			$this->recurse_copy($extractPath,$updateTargetPath);
			$this->recurse_copy($updateTargetPath."redundancy-$branch/",__REDUNDANCY_ROOT__);	 //for debugging is the target path set to /Temp/
			//Update the database
			$content = file_get_contents($updateTargetPath."Dump.sql");
			$queries = explode(";", $content);
			try{
				foreach ($queries as $key => $value) {
					try{

						DBLayer::GetInstance()->RunSelect($value);//$result =  $conn->query($value);
					}
					catch (\Exception $e){

					}					
				}
			}catch(\Exception $e){
				//return false;
			}
			//Cleanup
			$this->recursiveDelete($extractPath);
			$this->recursiveDelete($updateTargetPath."redundancy-$branch/");
			$this->recursiveDelete($targetPath."update.zip");
			return true;
		}
		/**
		* Copies the directory.
		* @param string $src sourcedir
		* @param string $dst destination dir
		*/
		function recurse_copy($src,$dst) { 
		    $dir = opendir($src); 
		    if (!is_dir($dst))
		    	@mkdir($dst); 
		    while(false !== ( $file = readdir($dir)) ) { 
		        if (( $file != '.' ) && ( $file != '..' )) { 
		            if ( is_dir($src . '/' . $file) ) { 
		                $this->recurse_copy($src . '/' . $file,$dst . '/' . $file); 
		            } 
		            else { 
		                copy($src . '/' . $file,$dst . '/' . $file); 
		            } 
		        } 
		    } 
		    closedir($dir); 
		}
		 /**
	     * Delete a file or recursively delete a directory
	     *
	     * @param string $str Path to file or directory
	     */
	    function recursiveDelete($str){
	        if(is_file($str)){
	            return @unlink($str);
	        }
	        elseif(is_dir($str)){
	            $scan = glob(rtrim($str,'/').'/*');
	            foreach($scan as $index=>$path){
	                $this->recursiveDelete($path);
	            }
	            return @rmdir($str);
	        }
	    }
		/**
		* Get the current version (as array/ dictionary)
		* @return array | empty string, if failure
		*/
		public function GetVersion(){					
			$currentVersion = $GLOBALS["Kernel"]->Version;
			$matches;
			if (!preg_match($this->pattern,$currentVersion,$matches))
				return "";
			else
				return $matches;
		}
		/**
		* Get the current branch name
		* @return the branch name (or empty string)
		*/
		public function GetBranch(){
			$matches =$this->GetVersion();
			if($matches == "")
				return "";
			return $matches["branch"];
		}
		/**
		* Grabs the latest version from the repository
		* return an array containing the latest version
		*/
		public function GetLatestVersion(){
			//grab the content from the latest version		
			$branch = $this->GetBranch();
			$url = sprintf($this->updateSource,$branch);	
			$content  = file_get_contents($url);
			$matches;
			if (!preg_match($this->pattern,$content,$matches)){
				return "";
			}
			else{
				return $matches;
			}
		}
		/**
		* Grabs the latest version from the repository and returns it as string. 
		* return an array containing the latest version"/(?<major>\d+).(?<minor>\d+).(?<patch>\d+)+-(?<branch>[^-]+)-(?<stage>[^-]+)-(?<update>\d+)/";
		*/
		public function GetLatestVersionAsString(){
			$result = $this->GetLatestVersion();
			return sprintf("%s.%s.%s-%s-%s-%s",$result["major"],$result["minor"],$result["patch"],$result["branch"],$result["stage"],$result["update"]);
		}
		/**
		* Get the source for updating
		* @return string the source
		*/
		public function GetUpdateSource(){
			$branch = $this->GetBranch();
			$url = sprintf($this->updatePackage,$branch);	
			return $url;
		}
	}
?>
