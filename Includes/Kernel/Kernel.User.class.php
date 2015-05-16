<?php
	/**
	* Kernel.User.class.php
	*/	
	namespace Redundancy\Kernel;
	/**
	* This file contains the user kernel, which contains all needed functions for managing the users of the program.
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
	* @todo implement functions to delete, edit user. Also the rights management.
	*/
	class UserKernel{
		/**
		* Hashes a given password
		* @param $password the given password
		* @return the password hash
		*/
		private function HashPassword($password){
			$options = [
				    'cost' => 11,
				    'salt' => mcrypt_create_iv(22, MCRYPT_DEV_URANDOM),
				];
			return password_hash($password, PASSWORD_BCRYPT, $options);
		}
		/**
		* Create a new system user, will be created using the settings User_Default_Role and User_Contingent
		* @param $loginName the username of the new user, e . g. "chuck"
		* @param $displayName the displayname of the new user, e. g. "Master of the Universe"
		* @param $mailAddress a mail adress of the user or null
		* @param $password the password of the user
		* @return \Redundancy\Classes\User an new object of Redundancy\Classes\User.class.php or an int value containing the error (taken from Redundancy\Classes\Errors)
		*/					
		public function RegisterUser($loginName,$displayName,$mailAddress,$password){
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$escapedMailAddress = DBLayer::GetInstance()->EscapeString($mailAddress,true);
			$escapedDisplayName = DBLayer::GetInstance()->EscapeString($displayName,true);
			$escapedPassword = DBLayer::GetInstance()->EscapeString($password,true);
			if (empty($escapedLoginName) || empty($escapedMailAddress) || empty($escapedDisplayName) || empty($escapedPassword))
				return \Redundancy\Classes\Errors::ArgumentMissing;
			if ($GLOBALS["Kernel"]->GetConfigValue("Enable_Register") == false)
				return \Redundancy\Classes\Errors::RegistrationNotEnabled;
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from User where loginName = '%s' or mailAddress = '%s'",$escapedLoginName,$escapedMailAddress));
			if ($dbquery[0]["Amount"] == 0){
				//Only proceed if there is no existing account with this email or loginName		
				$safetypass = $this->HashPassword($password);
				$registered= date("Y-m-d H:i:s",time());
				//$role = $GLOBALS["Kernel"]->Configuration["User_Default_Role"];
				$storage = $GLOBALS["Kernel"]->GetConfigValue("User_Contingent")*1024*1024;
				$user = new \Redundancy\Classes\User();				
				$user->LoginName = $escapedLoginName;
				$user->DisplayName = $escapedDisplayName;
				$user->MailAddress = $escapedMailAddress;
				$user->RegistrationDateTime = $registered;
				$user->LastLoginDateTime = null;
				$user->PasswordHash = $safetypass;
				$user->IsEnabled = true;
				$user->ContingentInByte = $storage;
				$systemRoles = $this->GetInstalledRoles();			
				foreach ($systemRoles as $value){
					if ($value->IsDefault)
					{
						$user->Role = $value;					
						break;
					}
				}				
				if (is_null($user->Role))
					return \Redundancy\Classes\Errors::RoleNotFound;
				$dbinsertion = sprintf("Insert into User (loginName,displayName,mailAddress,registrationDateTime,lastLoginDateTime,passwordHash,isEnabled,contingentInByte,roleID) values ('%s','%s','%s','%s','%s','%s','%u','%u','%u')",$user->LoginName,$user->DisplayName,$user->MailAddress,$user->RegistrationDateTime,$user->LastLoginDateTime,$user->PasswordHash,$user->IsEnabled,$user->ContingentInByte,$user->Role->Id);
				DBLayer::GetInstance()->RunInsert($dbinsertion);
				//Check if the user was created
				$checkquery = sprintf("Select id from User where loginName = '%s'",$user->LoginName);
				$checkresult = DBLayer::GetInstance()->RunSelect($checkquery);	
				//There is (now) no check if the db thrown an error while running this query. I guess that the program will explode sooner when the previous queries are being run.			
				$user->ID = $checkresult[0]["id"];
				return $user;					
			}else{
				return \Redundancy\Classes\Errors::UserOrEmailAlreadyGiven;				
			}			
		}
		/**
		* Checks if a given loginName is free and can be used
		* @param string $value the login name to search
		* @return bool the result of the check
		*/
		public function IsLoginOrMailFree($value){
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($value,true);
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from User where loginName = '%s' or mailAddress ='%s'",$escapedLoginName,$escapedLoginName));
			if ($dbquery[0]["Amount"] == 0)
				return true;
			else
				return false;
		}
		/**
		* Deletes the user after an successfull authentification
		* @todo add methods to delete the files, shares etc.
		* @param $loginName the users login name
		* @param $password the users password
		* @return bool the result of the deletion or an errorcode
		* @todo file deletion
		*/
		public function DeleteUser($loginName,$password){			
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$escapedPassword = DBLayer::GetInstance()->EscapeString($password,true);
			//Delete the sessions		
			
			if (!$this->Authentificate($escapedLoginName,$escapedPassword))
				return false;
			$token = $this->LogIn($escapedLoginName,$escapedPassword,false);
			
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($token,\Redundancy\Classes\PermissionSet::AllowDeletingUser))
				return \Redundancy\Classes\Errors::NotAllowed;	
			//Delete all files
			$deletion = $GLOBALS["Kernel"]->FileSystemKernel->DeleteDirectory("/",$token);
			if (!$deletion)
				return \Redundancy\Classes\Errors::CannotDeleteFolder;
 			
			//Kill all sessions
			$dbquery = DBLayer::GetInstance()->RunDelete(sprintf("Delete from Session where userID = (Select  id from User where User.loginName = '%s')",$escapedLoginName));
			$deleteShares = DBLayer::GetInstance()->RunDelete(sprintf("Delete from SharedFileSystem where userID = (Select  id from User where User.loginName = '%s')",$escapedLoginName));
			//Delete all settings.
			DBLayer::GetInstance()->RunDelete(sprintf("Delete from UserSettings where userId = (Select  id from User where User.loginName = '%s')",$escapedLoginName));
			$sessioncheck = DBLayer::GetInstance()->RunSelect(sprintf("Select count(s.id) as Amount from Session s inner join User u on u.id = s.userID where u.loginName = '%s' ",$escapedLoginName));
			
			//If the check returns values, there must be a problem and the deletion failed					
			//Delete the user			
			$dbquery = DBLayer::GetInstance()->RunDelete(sprintf("Delete from User where loginName = '%s'",$escapedLoginName));
			$check = DBLayer::GetInstance()->RunSelect(sprintf("Select count(u.id) as Amount from User u where u.loginName = '%s' ",$escapedLoginName));
			//If the check returns values, there must be a problem and the deletion failed		
			if ($check[0]["Amount"] != 0 || $sessioncheck[0]["Amount"] != 0)
				return false;	
			return true;
		}
		/**
		* Changes the password of the user
		* @param $token the valid session token for the user
		* @param $oldPassword the old user password
		* @param $newPassword the new user password
		* @return bool the result of the change
		*/
		public function ChangePassword($token,$oldPassword,$newPassword){
			$username = $this->GetUser($token);			
				
			if (is_null($username))
				return false;
			$username = $username->LoginName;
			if (!$this->Authentificate($username,$oldPassword))	
				return false;
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($token,\Redundancy\Classes\PermissionSet::AllowChangingPassword))
				return \Redundancy\Classes\Errors::NotAllowed;	
			$newHash = $this->HashPassword($newPassword);
			//Set the new password
			DBLayer::GetInstance()->RunUpdate("Update User set PasswordHash = '$newHash' where LoginName = '$username'");
			//Check if the password was set
			$check = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from User where PasswordHash = '%s'",$newHash));
			if (is_null($check) || $check[0]["Amount"] == 0)
				return false;			
			else
				return true;
		}
	
		/**
		* Generates a new random password
		* @todo generate an stronger password
		* @param int $length the length of the password. If not set, the value from User_Recover_Password_Length will be used
		* @return string the new password
		*/
		public function GeneratePassword($length = -1){
			//https://stackoverflow.com/questions/6101956/generating-a-random-password-in-php
			if ($length == -1)
				$length = $GLOBALS["Kernel"]->GetConfigValue("User_Recover_Password_Length");
			$alphabet = "abcdefghijklmnopqrstuwxyzABCDEFGHIJKLMNOPQRSTUWXYZ0123456789";
			$pass = '';                           //password is a string
			$alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
			for ($i = 0; $i < $length; $i++) {
				$n = mt_rand(0, $alphaLength);    
				$pass = $pass.$alphabet[$n];      //append a random character
			}
			return ($pass); 
		}
		/**
		* Resets a password by a mail
		* @param string $mailAddress the email of the account which password should be resetted
		* @todo check the systems email configuration to prevent mail sending when mail is not configured
		* @todo mail body
		* @todo Function not complete implemented
		* @todo permission check
		*/
		public function ResetPasswordByMail($mailAddress){			
			$mailAddress = DBLayer::GetInstance()->EscapeString($mailAddress,true);
			$check = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from User where MailAddress = '%s'",$mailAddress));
			if (is_null($check))
				return false;			
			if ($check[0]["Amount"] != "0")
			{
				//Send mail
				$domain = $_SERVER['HTTP_HOST'];
				$prefix = (isset($_SERVER['HTTPS']) && $_SERVER["HTTPS"] != "off") ? 'https://' : 'http://';
				$relative = str_replace('Includes/api.inc.php','index.php',$_SERVER['SCRIPT_NAME']);		
				$pw = $this->GeneratePassword(-1);
				$username = "";
				$tokenEndDateTime = date("Y-m-d H:i:s",time());
				$ip =$this->GetIP();
				$token = $this->GenerateToken($username,$tokenEndDateTime,$ip);
				$resetLink = $prefix.$domain.$relative."?resetpass&token=$token";
				//echo $resetLink;
				$content = sprintf($GLOBALS["Kernel"]->InterfaceKernel->GetLanguageValue("ResetLink"),$resetLink);
				//ResetLink
				$result =$this->InsertPasswordChange($token,$mailAddress);
				$result = !$GLOBALS["Kernel"]->SystemKernel->IsInTestEnvironment()? mail($mailAddress,"Redundancy",$content) : true;
				return $result;
			}
			else
				return false;	
		}
		/**
		* Inserts a password change request into the database
		* @param string $token the new token
		* @param string $mailAddress the mail address of the affected user
		* @return bool
		*/
		private function InsertPasswordChange($token,$mailAddress){
			$mailAddress = DBLayer::GetInstance()->EscapeString($mailAddress,true);
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$userQuery = DBLayer::GetInstance()->RunSelect(sprintf("Select id from User where MailAddress = '%s'",$mailAddress));
			if (is_null($userQuery))
				return false;	
			$tokenEndDateTime = date("Y-m-d H:i:s",time());
			$userId = $userQuery[0]["id"];
			//Destroy old requests
			$deletion = "Delete from PasswordRecoveries where UserId = $userId";
			DBLayer::GetInstance()->RunDelete($deletion);
			//Insert the request						
			$reset = "INSERT INTO PasswordRecoveries( UserId, Token, tokenEndDateTime ) 
			VALUES (
			'$userId',  '$escapedToken',  '$tokenEndDateTime'
			)";
			DBLayer::GetInstance()->RunInsert($reset);
			return true;
		}
		/**
		* Checks if a token is valid to reset the password
		* @param string $token the token which should me checked
		* @return bool 
		*/
		public function IsResetTokenValid($token,$expire = " + 1 day"){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$checkQuery = DBLayer::GetInstance()->RunSelect(sprintf("Select * from PasswordRecoveries where Token = '%s'",$escapedToken));
			if (is_null($checkQuery) || count($checkQuery) == 0)
				return false;
			$maxEndDate = date('Y-m-d H:i:s', strtotime($checkQuery[0]["TokenEndDateTime"]. $expire));
			$dateTime = date("Y-m-d H:i:s",time()); //The expire date is 24 hours
			if ($dateTime > $maxEndDate)
				return false;
			else
				return true; 
		}
		/**
		* Reset the password by a given request token
		* @param string $token the token to the request
		* @param string $newpass the new password
		* @return bool
		*/
		public function ResetPasswordByMailToken($token,$newpass){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$escapedNewPassword = DBLayer::GetInstance()->EscapeString($newpass,true);
			if (!$this->IsResetTokenValid($token))
				return false;
			//Set the password
			$checkQuery = DBLayer::GetInstance()->RunSelect(sprintf("Select * from PasswordRecoveries where Token = '%s'",$escapedToken));
			$userId = $checkQuery[0]["UserId"];	
			//Check if the user is allowed to change his/hers password...
			$usernameQuery = DBLayer::GetInstance()->RunSelect(sprintf("Select * from User where Id = '%s'",$userId));

			$role = $this->GetUserRole($usernameQuery[0]["loginName"]);
			$isAllowed = $this->CheckPermissions($role->Permissions,\Redundancy\Classes\PermissionSet::AllowChangingPassword);			
			if (!$isAllowed)
				return false;

			$hashed = $this->HashPassword($escapedNewPassword);
			$query = sprintf("Update User set passwordHash = '%s' where ID = %d",$hashed,$userId);
			DBLayer::GetInstance()->RunUpdate($query);

			//Destroy old requests
			$deletion = "Delete from PasswordRecoveries where UserId = $userId";
			DBLayer::GetInstance()->RunDelete($deletion);
			return true;
		}		
		public function DeleteUserByAdminPanel($loginname,$token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginname,true);	
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	

			if ($this->GetUser($escapedToken)->LoginName == $escapedLoginName)
				return \Redundancy\Classes\Errors::SystemAdminAccountNotAllowedToModify;
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from User where loginName = '%s'",$escapedLoginName));
			if ($dbquery[0]["Amount"] == 0)
				return \Redundancy\Classes\Errors::UserNotExisting;


			//Delete all files
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select * from FileSystem inner join User u on u.id = FileSystem.ownerId   where u.loginName = '%s'",$escapedLoginName));
			$storagePath = $GLOBALS["Kernel"]->FileSystemKernel->GetSystemDir(\Redundancy\Classes\SystemDirectories::Storage);
			if (!is_null($dbquery)){
				foreach ($dbquery as $value){
					if (!is_null($value)){
						unlink($storagePath.$value["filePath"]);
					}
				}
			}			
			DBLayer::GetInstance()->RunDelete(sprintf("Delete from FileSystem where ownerId = (Select id from User where loginName = '%s' limit 1)",$escapedLoginName));
			//Kill all sessions
			$dbquery = DBLayer::GetInstance()->RunDelete(sprintf("Delete from Session where userID = (Select  id from User where User.loginName = '%s')",$escapedLoginName));
			$deleteShares = DBLayer::GetInstance()->RunDelete(sprintf("Delete from SharedFileSystem where userID = (Select  id from User where User.loginName = '%s')",$escapedLoginName));
			$sessioncheck = DBLayer::GetInstance()->RunSelect(sprintf("Select count(s.id) as Amount from Session s inner join User u on u.id = s.userID where u.loginName = '%s' ",$escapedLoginName));
			//If the check returns values, there must be a problem and the deletion failed					
			//Delete the user			
			$dbquery = DBLayer::GetInstance()->RunDelete(sprintf("Delete from User where loginName = '%s'",$escapedLoginName));
			$check = DBLayer::GetInstance()->RunSelect(sprintf("Select count(u.id) as Amount from User u where u.loginName = '%s' ",$escapedLoginName));
			//If the check returns values, there must be a problem and the deletion failed		
			if ($check[0]["Amount"] != 0 || $sessioncheck[0]["Amount"] != 0)
				return false;	
			return true;

		}
		public function GetUserByAdminPanel($loginname,$token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginname,true);	
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	
			//if ($this->GetUser($escapedToken)->LoginName == $escapedLoginName)
			//	return \Redundancy\Classes\Errors::SystemAdminAccountNotAllowedToModify;
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select *,u.Id as UserID from User u where u.loginName =  '%s' limit 1",$escapedLoginName));		
			if (is_null($dbquery)){							
				return null;
			}			
			
			foreach ($dbquery as $value){
				//only proceed if the token was valid				
							
				$user = new \Redundancy\Classes\User();
				$user->ID = $value["UserID"];
				$user->LoginName = $value["loginName"];
				$user->DisplayName = $value["displayName"];
				$user->MailAddress = $value["mailAddress"];
				$user->RegistrationDateTime = $value["registrationDateTime"];
				$user->LastLoginDateTime = $value["lastLoginDateTime"];
				//@todo security?
				$user->PasswordHash = $value["passwordHash"];
				$user->IsEnabled = $value["isEnabled"];
				$user->ContingentInByte = $value["contingentInByte"];
				$user->Role = $this->GetUserRole($user->LoginName);
				$user->FailedLogins = $value["failedLogins"];
				$result = $user;			
			}			
			return $result;
		}
		/**
		* Updates the user
		* @param string $token the session token
		* @param string $loginName the login name to use
		* @param string $displayname
		* @param bool $enabled
		* @param int $contingentInByte
		* @param string $newPassword
		* @param string $group the groupname
		* @return bool| errorcode
		*/
		public function SetUserByAdminPanel($token,$loginName,$displayName,$enabled,$contingentInByte,$newPassword,$group){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	
			//values to set
			$escapedDisplayName = DBLayer::GetInstance()->EscapeString($displayName,true);
			$escapedEnabled = DBLayer::GetInstance()->EscapeString($enabled,true);			
			if ($escapedEnabled != true){
				if ($escapedEnabled == "on")
					$escapedEnabled = "1";
				else
					$escapedEnabled = "0";
			}			
			$escapedContingent = DBLayer::GetInstance()->EscapeString($contingentInByte,true);
			$escapedNewPassword = DBLayer::GetInstance()->EscapeString($newPassword,true);
			$escapedGroup = DBLayer::GetInstance()->EscapeString($group,true);
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$userToModify = $this->GetUserByAdminPanel($escapedLoginName,$escapedToken);
			$escapedEnabled = ($escapedEnabled == "on" ? true : false);
			//if ($this->GetUser($escapedToken)->LoginName == $userToModify->LoginName)
			//	return \Redundancy\Classes\Errors::SystemAdminAccountNotAllowedToModify;
			//update the values
			//Update displayname if needed
			if ($userToModify->DisplayName != $escapedDisplayName && $escapedDisplayName != ""){
				$query = sprintf("Update User set DisplayName = '%s' where ID = %d",$escapedDisplayName,$userToModify->ID);
				DBLayer::GetInstance()->RunUpdate($query);
			}
			//Update enabled state if needed
			if ($userToModify->IsEnabled != $escapedEnabled){
				if ($this->GetUser($escapedToken)->LoginName != $userToModify->LoginName){
					$query = sprintf("Update User set isEnabled = '%d' where ID = %d",$escapedEnabled,$userToModify->ID);
					DBLayer::GetInstance()->RunUpdate($query);
				}
				else{
					return \Redundancy\Classes\Errors::SystemAdminAccountNotAllowedToModify;
				}
			}
				
			//Update quota if needed
			if ($userToModify->ContingentInByte != $escapedContingent){
				$used = $this->GetStorageByAdminPanel($escapedToken,$userToModify->ID);
				//Only update if the size is greater than the currently used.
				if ($used->usedStorageInByte < $escapedContingent){
					$query = sprintf("Update User set contingentInByte = '%d' where ID = %d",$escapedContingent,$userToModify->ID);
					DBLayer::GetInstance()->RunUpdate($query);
				}
				else{
					return false;
				}
			}
			//Update password if needed
			if ($escapedNewPassword != ""){
				$hashed = $this->HashPassword($escapedNewPassword);
				$query = sprintf("Update User set passwordHash = '%s' where ID = %d",$hashed,$userToModify->ID);
				DBLayer::GetInstance()->RunUpdate($query);
			}
			
			//Update group if needed
			if ($escapedGroup != $userToModify->Role->Id){
				//Only update the group of other users to prevent locking out
				if ($this->GetUser($escapedToken)->LoginName != $userToModify->LoginName){					
					$installedGroups  = $this->GetInstalledRoles();
					foreach ($installedGroups as $key => $value) {
						if ($value->Id == $escapedGroup){
							$query = sprintf("Update User set roleID = '%d' where ID = %d",$value->Id,$userToModify->ID);
							DBLayer::GetInstance()->RunUpdate($query);
							break;
						}
					}
				}				
				else{
					return \Redundancy\Classes\Errors::SystemAdminAccountNotAllowedToModify;
				}	
			}					
			return true;
		}
		/**
		* Get the descriptions of the currently installed permissions
		* @return array containing the permissions
		*/
		public function GetPermissionValues(){
			return explode(",", \Redundancy\Classes\PermissionSet::CurrentPermissions);
		}
		/**
		* Get a Role by its name (description)
		* @param string $name the name to search
		* @return the role | errorcode
		*/
		public function GetRoleByName($name){
			$roles = $this->GetInstalledRoles();
			$role = null;
			foreach ($roles as $key => $value) {
				if ($value->Description == $name){
					$role =  $value;
					return $role;
				}
			}
			if (is_null($role))
				$role =  \Redundancy\Classes\Errors::PermissionNotFound;
			return $role;
		}
		/**
		* Get the value of a permission of a role
		* @param string $roleDesc the name of the role
		* @param string $permission the name of the permission
		* @return int the value or 40 (PermissionNotFound)
		*/
		public function GetPermission($roleDesc,$permission){
			$roles = $this->GetInstalledRoles();
			$role = null;
			foreach ($roles as $key => $value) {
				if ($value->Description == $roleDesc){
					$role = $value;
					break;
				}
			}
			if (is_null($role))
				return \Redundancy\Classes\Errors::PermissionNotFound;
			$rights = $this->GetPermissionValues();
			for ($i=0; $i < count($rights); $i++) { 
				if ($rights[$i] == $permission){
					return $role->Permissions[$i];
					break;
				}
			}
			return \Redundancy\Classes\Errors::PermissionNotFound;
		}
		/**
		* Update or creates a group (role)
		* @param string $roleName the role name to search/ create
		* @param string $roleId the ID to search or the string "-1" if you create a new group
		* @param string $rolePermissions the string which describes the permission of the role
		* @param string $token the session token
		* @return bool | int (in case of any errors)
		*/
		public function UpdateOrCreateGroup($roleName,$roleId,$rolePermissions,$token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	
			$permissionValue = DBLayer::GetInstance()->EscapeString($rolePermissions,true);
			$escapedRoleName = DBLayer::GetInstance()->EscapeString($roleName,true);
			$escapedRoleId = DBLayer::GetInstance()->EscapeString($roleId,true);
			$role = $this->GetRoleByName($escapedRoleName);
			if ($escapedRoleId == "-1"){
				//PRevent groups with the same name!
				if (!is_int($role))
					return \Redundancy\Classes\Errors::GroupNameAlreadyGiven;
				//Create new group;
				$query = sprintf("Insert into Role (description,permissions) values('%s','%s')",$escapedRoleName,$permissionValue);
			}else{
				//update
				if (!is_int($role) && $role->Id != $escapedRoleId)
					return \Redundancy\Classes\Errors::GroupNameAlreadyGiven;
				$query = sprintf("Update Role set permissions = '%s',description= '%s' where id = %d",$permissionValue,$escapedRoleName,$escapedRoleId);
			}
			//return $query;
			DBLayer::GetInstance()->RunInsert($query);
			return (is_numeric($this->GetRoleByName($escapedRoleName)) == false);
		}
		/**
		* Sets an group flag as the default one
		* @param string $roleName the name of the group
		* @param string $token a administrative session token
		* @return bool
		*/
		public function SetAsDefaultGroup($roleName,$token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	
			$escapedRoleName = DBLayer::GetInstance()->EscapeString($roleName,true);
			$roleSearchResult = $this->GetRoleByName($escapedRoleName);
			if (is_numeric($roleSearchResult))
				return false;
			//If the role is already default, no further actions are needed.
			if ($roleSearchResult->IsDefault)
				return true;
			$query = sprintf("Update Role set IsDefault = 0 where id <> '%d'",$roleSearchResult->Id);
			DBLayer::GetInstance()->RunUpdate($query);
			$query = sprintf("Update Role set IsDefault = 1 where id = '%d'",$roleSearchResult->Id);
			DBLayer::GetInstance()->RunUpdate($query);
			return true;
		}
		/**
		* Deletes a group with the given name
		* @param string $toleName the role name
		* @param string $token the token to use
		* @return bool | errorcode if an error occurs
		*/
		public function DeleteGroup($roleName,$token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	
			$escapedRoleName = DBLayer::GetInstance()->EscapeString($roleName,true);
			$roleSearchResult = $this->GetRoleByName($escapedRoleName);
			if (is_numeric($roleSearchResult))
				return false;
			if ($roleSearchResult->IsDefault)
				return \Redundancy\Classes\Errors::CannotDeleteDefaultGroup;
			$roles = $this->GetInstalledRoles();
			$result = false;
			foreach ($roles as $key => $value) {
				if ($value->IsDefault){
					$query = sprintf("Update User set roleID = '%d' where roleID='%d'",$value->Id,$roleSearchResult->Id);	
					DBLayer::GetInstance()->RunUpdate($query);
					$query =sprintf("Delete from Role where Id = '%d' limit 1",$roleSearchResult->Id);
					DBLayer::GetInstance()->RunDelete($query);
					$result = true;
					break;
				}
			}
			return $result;
		}
		/**
		* Translates the permission of a role to a string
		* @param Role the role to translate
		* @return string the permissions of the role
		*/
		public function GetPermissionValueFromRole($role){
			$permissions = "";
			foreach ($role->Permissions as $key => $value) {
				$permissions .= $value;
			}
			return $permissions;
		}
		/**
		* Get the storage informations
		* @param string $token a valid session token to identify the user
		* @param int $userId the target user id
		* @return \Redundancy\Classes\FileSystemAnalysis object or an errorcode
		*/
		public function GetStorageByAdminPanel($token,$userId){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowAdministration))
				return \Redundancy\Classes\Errors::NotAllowed;	
			$escapedId =DBLayer::GetInstance()->EscapeString($userId,true);			
			$result = new \Redundancy\Classes\FileSystemAnalysis();
			$result->sizeInByte = -1; //Not needed in this case		
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select sizeInByte from FileSystem where ownerID = '%d'",$escapedId));			
			if (is_null($dbquery))
				$result->usedStorageInByte = 0;
			else{
				foreach ($dbquery as $value){
					//only proceed if the token was valid				
					$result->usedStorageInByte = $result->usedStorageInByte + $value["sizeInByte"];
				}
			}				
			return $result;	
		}
		/**
		* Return the currently installed roles (e. g. admin, user, guest)
		* @return \Redundancy\Classes\Role[]|null an array containing the roles or null (in case of no roles and if the query failed)
		*/
		public function GetInstalledRoles(){
			$result = null;
			$dbquery = DBLayer::GetInstance()->RunSelect("Select * from Role");
			if (is_null($dbquery))
				return null;
			foreach ($dbquery as $value){
				if (is_null($result))
					$result = array();
				$role = new \Redundancy\Classes\Role();
				$role->Id = $value["id"];
				$role->Description = $value["description"];
				$role->Permissions = array();
				for ($i = 0; $i < strlen($value["permissions"]);$i++){
					$role->Permissions[] = $value["permissions"][$i];
				}	
				$role->IsDefault = $value["IsDefault"];		
				$result[] = $role;
			}			
			return $result;			
		}
		/**
		* Check if an action is allowed
		* @param string $token a valid session token to identify the user
		* @param int $permission the permission to check. Can be taken out of \Redundancy\Classes\PermissionSet
		* @return bool the result of the check
		*/
		public function IsActionAllowed($token,$permission){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$escapedPermission = DBLayer::GetInstance()->EscapeString($permission,true);				
			$user = $this->GetUser($escapedToken);
			if (is_null($user)){					
				return false;
			}		
			$permissions = $user->Role->Permissions;	
			return $this->CheckPermissions($permissions,$escapedPermission);
		}
		/**
		* Checks if a escapedPermission is allowed
		* @param array $permissions the permissions to check
		* @param string $escapedPermissions the index to check
		* @return bool
		*/
		private function CheckPermissions($permissions,$escapedPermission){
			if (is_null($permissions) || $escapedPermission > count($permissions) -1)		
				return false;	
			if ($permissions[$escapedPermission] == "0")
				return false;
			else
				return true;
		}
		/**
		* Get the users role 
		* @param $loginName the user login name
		* @return \Redundancy\Classes\Role|null or (if the query failed) null
		*/
		private function GetUserRole($loginName){
			$result = null;
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select *,r.ID as RoleID from Role r inner join User u on u.roleID = r.id where  u.loginName = '%s' ",$escapedLoginName));
			if (is_null($dbquery))
				return null;
			foreach ($dbquery as $value){
				$role = new \Redundancy\Classes\Role();
				$role->Id = $value["RoleID"];
				$role->Description = $value["description"];
				//Iterate the permissions
				$role->Permissions = array();
				for ($i = 0; $i < strlen($value["permissions"]);$i++){
					$role->Permissions[] = $value["permissions"][$i];
				}	
				$role->IsDefault = $value["IsDefault"];							
				$result = $role;
			}			
			return $result;	
		}
		public function GetUserNameById($loginname, $token){
			$result = null;			
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginname,true);
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$user = $GLOBALS["Kernel"]->UserKernel->GetUser($escapedToken);			
			if (is_null($user))
				return \Redundancy\Classes\Errors::TokenNotValid;
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select *,u.Id as UserID from User u where u.Id ='%d' limit 1",$escapedLoginName));		
			if (is_null($dbquery)){							
				return null;
			}					
			foreach ($dbquery as $value){							
				$user = new \Redundancy\Classes\User();
				$user->ID = $value["UserID"];
				$user->LoginName = $value["loginName"];
				$user->DisplayName = $value["displayName"];				
				$result = $user;			
			}			
			return $result;	
		}
		/**
		* Get the user object by the current session. If there is no fitting session to this token, null will be returned
		* Also, when the session is expired, this will be null
		* @param $token the current session token
		* @return \Redundancy\Classes\User|null user or, if failed, null
		*/ 
		public function GetUser($token){
			$result = null;			
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select *,u.Id as UserID from User u inner join Session s on s.userId = u.ID where  s.token = '%s' limit 1",$escapedToken));		
			if (is_null($dbquery)){							
				return null;
			}			
			
			foreach ($dbquery as $value){
				//only proceed if the token was valid				
				if ($this->IsNewSessionNeeded($value["loginName"]) == "true"){						
					return null;
				}					
				$user = new \Redundancy\Classes\User();
				$user->ID = $value["UserID"];
				$user->LoginName = $value["loginName"];
				$user->DisplayName = $value["displayName"];
				$user->MailAddress = $value["mailAddress"];
				$user->RegistrationDateTime = $value["registrationDateTime"];
				$user->LastLoginDateTime = $value["lastLoginDateTime"];
				$user->IsEnabled = $value["isEnabled"];
				$user->ContingentInByte = $value["contingentInByte"];
				$user->Role = $this->GetUserRole($user->LoginName);
				$user->FailedLogins = $value["failedLogins"];
				$result = $user;			
			}			
			return $result;	
		}
		/**
		* Generates an unique Token to store the session
		* @param $loginName the user's login name
		* @param $dateTime the current date and time
		* @param $ip the clients IP
		* @todo: Fix these fucking $_SERVER values
		* @return string a string containing the token
		*/
		private function GenerateToken($loginName,$dateTime,$ip){
			$token = \Redundancy\Classes\Errors::TokenGenerationFailed;
			$userAgent = "";						
			//if (isset($_SERVER['HTTP_USER_AGENT']))
				//$userAgent = $_SERVER['HTTP_USER_AGENT'];			
			$token = md5(md5($loginName).md5($dateTime).md5($ip).md5($userAgent));
			return $token;		
		}
		/**
		* Do an single check of the user credentials
		* @param $loginName the user's login name
		* @param $password the user's password
		* @return bool the result of the check
		*/
		public function Authentificate($loginName,$password){
			$result = false;
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$escapedPassword = DBLayer::GetInstance()->EscapeString($password,true);
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select passwordHash from User where loginName ='%s'",$escapedLoginName));
			if (is_null($dbquery))
				return false;			
			if(password_verify($escapedPassword,$dbquery[0]["passwordHash"])){
				$this->ResetFailedLoginsCounter($escapedLoginName);
				$result = true;
			}
			else
			{		
				$this->IncreaseFailedLoginsCounter($escapedLoginName);
				$result = false;		
			}		
			return $result;
		}
		/**
		* Get the clients IP
		* @returns string the IP
		*/
		public function GetIP(){			
			if (isset($_POST["ip"]))
				return DBLayer::GetInstance()->EscapeString($_POST["ip"],true);	
			//Cloudflare related fixes..
			if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])){
				$_SERVER['HTTP_CLIENT_IP'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
			}

			if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			    $ip = $_SERVER['HTTP_CLIENT_IP'];
			} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
			} else {
			   	if (isset($_SERVER['REMOTE_ADDR']))
			    	$ip = $_SERVER['REMOTE_ADDR'];
			    else
			    	$ip = "127.0.0.1";
			}		
			return $ip;
		}
		/**
		* Logs the user in and returns an Errorcode from Errors if failure or the session token.  Uses the session timeout from Program_Session_Timeout
		* @param $loginName the user's login name
		* @param $password the user's password
		* @param $stayLoggedIn determines if the user want's to keep logged in.
		* @return string a string containing the token or an error code
		*/	
		public function LogIn($loginName,$password,$stayLoggedIn){
			$result = \Redundancy\Classes\Errors::PasswordOrUserNameWrong;
			$passwordToCheck = "";
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$escapedPassword = DBLayer::GetInstance()->EscapeString($password,true);
			$ip =$this->GetIP();
 			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select Id,passwordHash,isEnabled from User where loginName ='%s'",$escapedLoginName));
			$sessionStartedDateTime = date("Y-m-d H:i:s",time());
			
			if (is_null($dbquery))
				return \Redundancy\Classes\Errors::PasswordOrUserNameWrong;
			if (password_verify($escapedPassword,$dbquery[0]["passwordHash"])){
				if ($dbquery[0]["isEnabled"] == 0)
					return \Redundancy\Classes\Errors::UserDisabled;
				$this->ResetFailedLoginsCounter($escapedLoginName);
				//Delete old sessions to make sure there are no Sessions with an too old EndDate
				$this->DeleteExpiredSession($escapedLoginName);		
				$this->SetLastLoginDateTime($escapedLoginName);

				$sessionNeeded = $this->IsNewSessionNeeded($escapedLoginName);		

				//this value can be a kind of bool				
				if ($sessionNeeded != "true"){
					return $sessionNeeded;				
				}
				else{
					$token =  $this->GenerateToken($escapedLoginName,$sessionStartedDateTime,$ip);
					$userId = $dbquery[0]["Id"];					
					if ($stayLoggedIn || $GLOBALS["Kernel"]->GetConfigValue("Program_Session_Timeout") == -1){
						$dbquery = sprintf("Insert into Session (userId,token,sessionStartedDateTime,sessionEndDateTime) values('%u','%s','%s','%s')",$userId,$token,$sessionStartedDateTime,null);
						if (!$GLOBALS["Kernel"]->SystemKernel->IsInTestEnvironment())
							$this->CreateCookie($token,false);						
					}else{
					
						$minutes = $GLOBALS["Kernel"]->GetConfigValue("Program_Session_Timeout");					
						$sessionEndDateTime = date("Y-m-d H:i:s",strtotime("+$minutes minutes"));
						$dbquery = sprintf("Insert into Session (userId,token,sessionStartedDateTime,sessionEndDateTime) values('%u','%s','%s','%s')",$userId,$token,$sessionStartedDateTime,$sessionEndDateTime);
					}
					DBLayer::GetInstance()->RunInsert($dbquery);
					return $token;
				}
			}
			else{
				$this->IncreaseFailedLoginsCounter($escapedLoginName);
			}
			return $result;
		}
		/**
		* Kill the session cookie	
		* @return the result of the overwrite process with an empty value	
		*/
		private function KillSessionCookie(){			
			return setcookie('SessionData', '', time() - 3600);			
		}
		/**
		* Get a session by a cookie
		* @return string the cookie token or -1
		*/
		public function GetSessionByCookie(){
			if (!isset($_COOKIE["SessionData"]))
				return -1;
			else
				return $_COOKIE["SessionData"];
		}
		/**
		* Creates a cookie for the given token session
		* uses the configuration value Program_Session_Timeout, if not set, the default cookie lifetime will be 5 minutes.
		* @param $token the token
		* @param $expires bool determines if the cookie should expire
		*/
		private function CreateCookie($token,$expires = false){
			ob_end_clean();	
			if ($expires){
				$cookieLifeSpan = $GLOBALS["Kernel"]->GetConfigValue("Program_Session_Timeout");	
				if ($cookieLifeSpan == -1)
				{
					$cookieLifeSpan = 5;
				}		
				$res = setcookie("SessionData", $token, time()+ $cookieLifeSpan * 60);	
				error_log("Cookie set: $res");
			}
			else{				
				$res = setcookie("SessionData", $token);
				error_log("Cookie set: $res");
			}						
		}
		/**
		* Set the last Login Date and time
		* @param $loginName the username of the logging in user
		*/
		private function SetLastLoginDateTime($loginName){
			$value = date("Y-m-d H:i:s",time());
			$loginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$query = sprintf("Update User set LastLoginDateTime = '$value' where loginName = '%s'",$loginName);	
			DBLayer::GetInstance()->RunUpdate($query);

		}
		/**
		* Increases the failure counter of failed logins
		* @param $loginName the username
		*/
		private function IncreaseFailedLoginsCounter($loginName){
			$loginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$query = sprintf("Update User set FailedLogins = FailedLogins +1 where loginName = '%s'",$loginName);		
			DBLayer::GetInstance()->RunUpdate($query);
		}
		/**
		* Resets the failure counter.
		* @param $loginName the username
		*/
		private function ResetFailedLoginsCounter($loginName){
			$loginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$query = sprintf("Update User set FailedLogins = 0 where loginName = '%s'",$loginName);	
			DBLayer::GetInstance()->RunUpdate($query);
		}
		/**
		* Resets the failure counter.
		* @param $loginName the username
		* @return int the amount of failed logins
		*/
		private function GetFailedLoginsCounter($loginName){
			$loginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$query = sprintf("Select FailedLogins from User where loginName = '%s'",$loginName);	
			$result = DBLayer::GetInstance()->RunSelect($query);		
			if (is_null($query))
				return 0;
			return $result[0]["FailedLogins"];
		}
		/**
		* Delete a single token from the database
		* @param $token the token to delete
		* @return bool the result of the deletion. If the token was not existing, the function returns false;
		*/
		public function KillSessionByToken($token){
			$result = false;
			if (!$this->IsSessionExisting($token))
				$result = false;
			else{
				$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);							
				if (!$GLOBALS["Kernel"]->SystemKernel->IsInTestEnvironment())
					$this->KillSessionCookie();
				DBLayer::GetInstance()->RunDelete(sprintf("Delete from Session where Token = '%s'",$escapedToken));
				if (!$this->IsSessionExisting($token))
					$result =  true;
			}
			return $result;
		}		
		/**
		* Check if a single token exists in the database;
		* @param $token the token to search
		* @return bool he result of the check
		*/
		public function IsSessionExisting($token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$check = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from Session where Token = '%s'",$escapedToken));
			if (is_null($check))
				return false;			
			if ($check[0]["Amount"] != "0")
				return true;
			else
				return false;
		}
		/**
		* Checks if the user can create a new session or an old one is still active
		* @param $loginName the username to search
		* @return bool|string The result of the check or the token
		*/
		private function IsNewSessionNeeded($loginName){
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select token,sessionStartedDateTime,sessionEndDateTime from Session inner join User u on u.Id = userId where u.loginName = '%s'",$escapedLoginName));
		
			//If there is no token, a new one can be created
			if (is_null($dbquery) || count($dbquery) == 0)
				return "true";				
			foreach ($dbquery as $value){				
				$ip =$this->GetIP();	

				$sessionStartedDateTime = $value["sessionStartedDateTime"];			
				$compareToken = $this->GenerateToken($escapedLoginName,(string)$sessionStartedDateTime,$ip);	
				//TODO: fix this			
				//error_log($compareToken);	
				//error_log($compareToken);
				//error_log($value["token"]);			
				if ($compareToken == $value["token"]){	
					if ($value["sessionEndDateTime"] == "0000-00-00 00:00:00"){						
						return $compareToken;
					}else{
						$currentDateTime = date("Y-m-d H:i:s",time());
						$sessionEndDateTime = date("Y-m-d H:i:s",strtotime($value["sessionEndDateTime"]	));					
						if ($currentDateTime >= $sessionEndDateTime)
							return "true";
						else
							return $compareToken;			
					}
				}
			}
			//When no token has fit
			return "true";		
		}		
		/**
		* Delete old sessions (except it is a keep alive session!)
		* @param string $loginName the login name
		*/
		private function DeleteExpiredSession($loginName){
			$escapedLoginName = DBLayer::GetInstance()->EscapeString($loginName,true);
			$currentDateTime = date("Y-m-d H:i:s",time());
			$query = sprintf("Delete from Session where sessionEndDateTime <> '0000-00-00 00:00:00' and sessionEndDateTime < '%s' and userID = (Select ID from User where loginName = '%s' limit 1)", $currentDateTime,$loginName);
			DBLayer::GetInstance()->RunDelete($query);
		}
		/**
		* Get the permissions of an user
		* @param string $token the session token
		* @return array | errorcode
		*/
		public function GetPermissionSet($token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$user =  $this->GetUser($token);
			if(is_null($user))
				return \Redundancy\Classes\Errors::TokenNotValid;
			$permissionSet = $user->Role->Permissions;	
			return $permissionSet;
		}	
		/**
		* Get the display names of the rights which can be set
		* @param string $token the session token
		* @return array | errorcode
		*/
		public function GetListOfInstalledPermissions($token){
			$escapedToken = DBLayer::GetInstance()->EscapeString($token,true);
			$user =  $this->GetUser($token);
			if(is_null($user))
				return \Redundancy\Classes\Errors::TokenNotValid;
			$permissionSet = explode(",",\Redundancy\Classes\PermissionSet::CurrentPermissions);
			return $permissionSet;
		}	
		/**
		* Get a user defined setting
		* @param string $name the setting name (must be one of the returned of GetDefaultSettingsSet())
		* @param string $token the session token
		* @return mixed the settings value or null when the setting does not exist
		*/
		public function GetUserSetting($name,$token){
			$escapedName= DBLayer::GetInstance()->EscapeString($name,true);
			$escapedToken= DBLayer::GetInstance()->EscapeString($token,true);
			$user = $this->GetUser($escapedToken);				
			if (is_null($user))
				return false;
			$query = sprintf("Select * from UserSettings where SettingName = '%s' and UserId = '%s'",$escapedName,$user->ID);			
			$dbquery = DBLayer::GetInstance()->RunSelect($query);
			if (is_null($dbquery) || count($dbquery) == 0)
				return null;
			$s = null;
			foreach ($dbquery as $value){
				$s = new \Redundancy\Classes\Setting();
				$s->Name = $value["SettingName"];
				$s->Type = $value["SettingType"];
				//A special bool handling
				if ($s->Type =="Boolean")
					$s->Value = ($value["SettingValue"] == "true")? true : false;
				else
					$s->Value = $value["SettingValue"];
			}
			return $s;
		}
		/**
		* Set a new setting
		* @param string $name the name
		* @param string $type the type ("Boolean","Text","Number")
		* @param mixed $value the value to use
		* @param string $token the session token
		* @return bool
		*/
		public function SetDefaultSetting($name,$type,$value,$token){
			$escapedName= DBLayer::GetInstance()->EscapeString($name,true);
			$escapedType= DBLayer::GetInstance()->EscapeString($type,true);
			$escapedValue= DBLayer::GetInstance()->EscapeString($value,true);
			$escapedToken= DBLayer::GetInstance()->EscapeString($token,true);
			$user = $this->GetUser($escapedToken);		
			if (is_null($user))
				return false;
			//count the setting to prevent duplicate ones
			$dbquery = DBLayer::GetInstance()->RunSelect(sprintf("Select count(id) as Amount from UserSettings where SettingName = '%s' and UserId = '%s'",$escapedName,$user->ID));
			if ($dbquery[0]["Amount"] != 0)
				return false;
			$query = sprintf("Insert into UserSettings (SettingName,SettingType,SettingValue,UserId) VALUES ('%s','%s','%s','%s')",$escapedName,$escapedType,$escapedValue,$user->ID);
			DBLayer::GetInstance()->RunInsert($query);
			return true;
		}
		/**
		* Get the default list of user settings
		* @return \Redundancy\Classes\Setting[] the settings
		*/
		public function GetDefaultSettingsSet(){
			$settings = array();
			$s = new \Redundancy\Classes\Setting();
			$s->Name = "ui-user-scalable";
			$s->Type="Boolean";
			$s->Value = "false";
			$settings[$s->Name] = $s;
			return $settings;
		}
		public function SetUserSetting($name,$value,$token){
			$escapedToken= DBLayer::GetInstance()->EscapeString($token,true);
			if (!$GLOBALS["Kernel"]->UserKernel->IsActionAllowed($escapedToken,\Redundancy\Classes\PermissionSet::AllowUserSettings))
				return \Redundancy\Classes\Errors::NotAllowed;				
			$user = $this->GetUser($escapedToken);			
			if (is_null($user))
				return false;
			if (is_null($this->GetUserSetting($name,$token))){
				//The Setting is not existing
				$defaults = $this->GetDefaultSettingsSet();
				$this->SetDefaultSetting($defaults[$name]->Name,$defaults[$name]->Type,$defaults[$name]->Value,$token);
			}
			$escapedName =  DBLayer::GetInstance()->EscapeString($name,true);
			$escapedValue=  DBLayer::GetInstance()->EscapeString($value,true);
			$query = sprintf("Update UserSettings set SettingValue = '%s' where SettingName = '%s' and UserId = '%s'",$escapedValue, $escapedName,$user->ID);	
			$dbquery = DBLayer::GetInstance()->RunUpdate($query);							
			return true;
		}
	}
?>
