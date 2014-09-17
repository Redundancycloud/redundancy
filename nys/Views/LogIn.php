<div class='col-md-4'></div>
<div class='col-md-4'>
<?php
  //Display the error message if needed.
  if (isset($ERROR))
    include 'Partials/ErrorMessage.php';
  //Display other messages
  if (isset($MESSAGE))
    include 'Partials/Message.php';   
?>
<div class="hidden-xs">
<img class='logo' src='./nys/Views/img/logo.png'>
</div>
<h2 class='appname'><?php  echo $GLOBALS['Router']->DoRequest('Kernel','GetAppName',json_encode(array())); ?></h2>
<div class='panel panel-default'>
<form class ='panel-body' role='form' method='POST' action='?login'>
  <div class='form-group '>
    <label for='username'><?php echo $GLOBALS['Language']->Username;?></label>
    <input type='text' class='form-control' name='username' placeholder='<?php echo $GLOBALS['Language']->Username;?>'>
  </div>
  <div class='form-group'>
    <label for='password'><?php echo $GLOBALS['Language']->Password; ?></label>
    <input type='password' class='form-control' name='password' placeholder='<?php echo $GLOBALS['Language']->Password;?>'>
  </div> 
  <div class='form-group'>
	  <label for='password'><?php echo $GLOBALS['Language']->Lang; ?></label>
		<select class='form-control' id='lang' name='lang'>
				<?php 
					$languages = $GLOBALS['Router']->DoRequest('Kernel.InterfaceKernel','GetInstalledLanguages',json_encode(array()));				
				?>
				<?php foreach($languages as $key=>$value): ?>			  
			        <option><?php echo $value; ?></option>				   
			    <?php endforeach; ?>	
		</select>
  </div>
  <div class='checkbox'>
    <label>
      <input name="stayloggedin" type='checkbox' value='true'><?php echo $GLOBALS['Language']->StayLoggedIn;?>
    </label>
  </div>
  <button type='submit' class='btn btn-default'><?php echo $GLOBALS['Language']->Log_In;?></button>
  <?php if ($isRegistrationEnabled) :?>
    <a href="?register" class="btn btn-default"><?php echo $GLOBALS['Language']->Register;?></a>
  <?php endif;?>
</form>
</div>
</div>
<div class='col-md-4'></div>