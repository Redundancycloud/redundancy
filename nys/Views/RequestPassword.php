<div class='col-md-4'></div>
<div class='col-md-4'>
<?php //Display the error message if needed.
    if (isset($ERROR))
      include 'Partials/ErrorMessage.php';
?>
<?php //Display the error message if needed.
    if (isset($MESSAGE))
      include 'Partials/Message.php';
?>
    <div class="hidden-xs">
        <img class='img-responsive logo' src='./nys/Views/img/logoWithText.png'>
    </div>
    <div class='panel panel-default white-flat'>
        <div class="panel-body">
            <h1 class="light header-form gray">
                <?php echo $GLOBALS['Language']->reset_pass;?></h1>
            <form class='form' role='form' method='POST' action='?requestpass'>                
                <div class='form-group'>
                    <label for='email'>
                        <?php echo $GLOBALS['Language']->Email; ?></label>
                    <input type='text' class='form-control' name='email' placeholder='<?php echo $GLOBALS['Language']->Email;?>'>
                </div>             
                <div class="btn-group">
                    <button type='submit' class='btn btn-primary'>
                        <?php echo $GLOBALS['Language']->Save;?></button>
                    <a class="btn btn-default" href="index.php"><?php echo $GLOBALS['Language']->Back;?></a>
                </div>
            </form>
        </div>
    </div>
</div>
<div class='col-md-4'></div>
