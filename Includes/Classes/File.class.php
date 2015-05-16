<?php	
	/**
	* File.class.php
	*/	
	namespace Redundancy\Classes;
	/**
	 * This file contains the base class for files
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
	 **/	
	class File extends FileSystemItem{
		/**
		* The filesize in Bytes			
		*/
		public $SizeInBytes;
		/**
		* The filesize with the Unit			
		*/
		public $SizeWithUnit;
		/**
		* The used upload user agent
		*/		
		public $UsedUserAgent;
	}
?>
