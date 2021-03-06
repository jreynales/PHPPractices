<?php
// This file is the main body of the Warm Mail application.
// It works basically as a state machine and shows users the
// output for the action they have chosen.  

//*****************************************************************************
// Stage 1: pre-processing
// Do any required processing before page header is sent
// and decide what details to show on page headers
//*****************************************************************************

  include ('include_fns.php');
  session_start();
  //create short variable names
  $username = $_POST['username'];
  $passwd = $_POST['passwd'];
  $action = $_REQUEST['action'];
  $account = $_REQUEST['account'];
  $messageid = $_GET['messageid'];
  
  $to =  $_POST['to'];
  $cc =  $_POST['cc'];
  $subject =  $_POST['subject'];
  $message =  $_POST['message'];
  
  $buttons = array();
  
  //append to this string if anything processed before header has output 
  $status = '';
   
  // need to process log in or out requests before anything else
  if($username||$password)
  {
    if(login($username, $passwd))
    {
      $status .= '<p>Logged in successfully.</p><br /><br /><br /><br />
       <br /><br />';
      $_SESSION['auth_user'] = $username;
      if(number_of_accounts($_SESSION['auth_user'])==1)
      {
        $accounts = get_account_list($_SESSION['auth_user']); 
        $_SESSION['selected_account'] = $accounts[0]; 
      }
    }
    else
    {
      $status .= '<p>Sorry, we could not log you in with that 
                  username and password.</p><br /><br /><br /><br />
                  <br /><br />';
    }
  }
  if($action == 'log-out')
  {
    session_destroy();
    unset($action);
    $_SESSION=array();
  }
  
  //need to process choose, delete or store account before drawing header
  switch ( $action )
  {
    case 'delete-account' :
    {
      delete_account($_SESSION['auth_user'], $account);
      break;
    }
    case 'store-settings' :
    {
      store_account_settings($_SESSION['auth_user'], $_POST);
      break;
    }
    case 'select-account' :
    { 
      // if have chosen a valid account, store it as a session variable
      if($account&&account_exists($_SESSION['auth_user'], $account))
      {
        $_SESSION['selected_account'] = $account;
      }
    }
  }
  // set the buttons that will be on the tool bar
  $buttons[0] = 'view-mailbox'; 
  $buttons[1] = 'new-message';
  $buttons[2] = 'account-setup';  
  //only offer a log out button if logged in
  if(check_auth_user())
  {
    $buttons[4] = 'log-out'; 
  }

//*****************************************************************************
// Stage 2: headers 
// Send the HTML headers and menu bar appropriate to current action 
//*****************************************************************************  
  if($action)
  {
    // display header with application name and description of page or action
    do_html_header($_SESSION['auth_user'], "Warm Mail - ".
                   format_action($action), 
                   $_SESSION['selected_account']);
  }
  else
  {
    // display header with just application name
    do_html_header($_SESSION['auth_user'], "Warm Mail", 
     $_SESSION['selected_account']);
  }
  
  display_toolbar($buttons);
  
//*****************************************************************************
// Stage 3: body 
// Depending on action, show appropriate main body content 
//*****************************************************************************  
  //display any text generated by functions called before header
  echo $status;

  if(!check_auth_user())
  {
    echo '<p>You need to log in';
    if($action&&$action!='log-out')
      echo ' to go to '.format_action($action);
    echo '.</p><br /><br />';
    display_login_form($action);
  }
  else
  {
    switch ( $action )
    {
      // if we have chosen to setup a new account, or have just added or 
      // deleted an account, show account setup page
      case 'store-settings' :
      case 'account-setup' :
      case 'delete-account' :
      {
        display_account_setup($_SESSION['auth_user']);
        break;
      }
      case 'send-message' :
      {
        if(send_message($to, $cc, $subject, $message))
          echo '<p>Message sent.</p><br /><br /><br /><br /><br /><br />';
        else 
          echo '<p>Could not send message.</p><br /><br /><br /><br />
                <br /><br />';
        break;
      }
      case 'delete' :
      {
         delete_message($_SESSION['auth_user'], 
                        $_SESSION['selected_account'], $messageid);
         //note deliberately no 'break' - we will continue to the next case
      }
      case 'select-account' :
      case 'view-mailbox' :
      {
        // if mailbox just chosen, or view mailbox chosen, show mailbox
        display_list($_SESSION['auth_user'], 
          $_SESSION['selected_account']);
        break;
      }
      case 'show-headers' :
      case 'hide-headers' :
      case 'view-message' :
      {
        // if we have just picked a message from the list, or were looking at 
        // a message and chose to hide or view headers, load a message 
        $fullheaders = ($action=='show-headers'); 
        display_message($_SESSION['auth_user'], 
                        $_SESSION['selected_account'], 
                        $messageid, $fullheaders);
        break;
      }
      case 'reply-all' :
      { 
        //set cc as old cc line 
        if(!$imap)
          $imap = open_mailbox($_SESSION['auth_user'], 
                               $_SESSION['selected_account']);
        if($imap)
        {
          $header = imap_header($imap, $messageid);
          if($header->reply_toaddress)
            $to = $header->reply_toaddress;
          else
            $to = $header->fromaddress;
          $cc = $header->ccaddress;
          $subject = 'Re: '.$header->subject;
          $body = add_quoting(stripslashes(imap_body($imap, $messageid)));
          imap_close($imap);
        
          display_new_message_form($_SESSION['auth_user'], 
          $to, $cc, $subject, $body);
        }
        break;
      }
      case 'reply' :
      {
        //set to address as reply-to or from of the current message
        if(!$imap)
          $imap = open_mailbox($_SESSION['auth_user'], 
                               $_SESSION['selected_account']);
        if($imap)
        {
          $header = imap_header($imap, $messageid);
          if($header->reply_toaddress)
            $to = $header->reply_toaddress;
          else
            $to = $header->fromaddress;
          $subject = 'Re: '.$header->subject;
          $body = add_quoting(stripslashes(imap_body($imap, $messageid)));
          imap_close($imap);
          
          display_new_message_form($_SESSION['auth_user'], 
                                   $to, $cc, $subject, $body);
        }       
        
        break;        
      }
      case 'forward' :
      {
        //set message as quoted body of current message
        if(!$imap)
          $imap = open_mailbox($_SESSION['auth_user'], 
                               $_SESSION['selected_account']);
        if($imap)
        {
          $header = imap_header($imap, $messageid);
          $body = add_quoting(stripslashes(imap_body($imap, $messageid)));
          $subject = 'Fwd: '.$header->subject;
          imap_close($imap);
          
          display_new_message_form($_SESSION['auth_user'], 
                                   $to, $cc, $subject, $body);
        }
        break;
      }
      case 'new-message' :
      {               
        display_new_message_form($_SESSION['auth_user'], 
                                 $to, $cc, $subject, $body);
        break;
      }
    }
  } 
//*****************************************************************************
// Stage 4: footer 
//*****************************************************************************
  do_html_footer();
?>
