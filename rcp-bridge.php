<?php

class FV_Player_Pro_RCP_Bridge {

  function __construct() {

    add_action( 'admin_init', array( $this, 'admin__add_meta_boxes' ), 11 );
    add_action( 'plugins_loaded', array( $this, 'init' ), 11 );

    if( isset($_GET['page']) && $_GET['page'] == 'rcp-reports' && isset($_GET['tab']) && $_GET['tab'] == 'earnings' ) {
      add_filter( 'query', array( $this, 'rcp_fix_reports' ) );
    }

  }




  function admin__add_meta_boxes() {
    global $fv_fp;
    if( isset($fv_fp->conf['pro']['ppv_rcp_levels']) && is_array($fv_fp->conf['pro']['ppv_rcp_levels']) && count($fv_fp->conf['pro']['ppv_rcp_levels']) > 0 ) {
      if( count($fv_fp->conf['pro']['ppv_rcp_levels']) == 1 && !empty($fv_fp->conf['pro']['ppv_rcp_levels'][-1]) ) return;

      add_meta_box( 'fv_player_pro_rcp_bridge', __('Pay Per View (Pro) (beta)', 'fv-wordpress-flowplayer'), array( $this, 'admin__meta_box' ), 'fv_flowplayer_settings', 'normal', 'low' );
    }
  }




  function admin__meta_box() {
    global $fv_fp;
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <?php if( class_exists('RCP_Member') && function_exists('rcp_get_membership_levels') && function_exists('rcp_get_subscription_details') ) : ?>
        <tr>
          <td style="width: 250px"><label for="pro[ppv_rcp_levels]">Pick subscription levels:</label></td>
          <td>
            <?php
            $aLevels = isset($fv_fp->conf['pro']['ppv_rcp_levels']) && is_array($fv_fp->conf['pro']['ppv_rcp_levels']) ? $fv_fp->conf['pro']['ppv_rcp_levels'] : array();
            ?><ul>
            <?php foreach( rcp_get_membership_levels() AS $objLevel ) : ?>
              <input type="hidden" value="-1" name="pro[ppv_rcp_levels][-1]" />
              <li>
                <input type="checkbox" value="<?php echo $objLevel->id ?>" name="pro[ppv_rcp_levels][]" id="pro[ppv_rcp_levels][<?php echo $objLevel->id ?>]" <?php if( in_array($objLevel->id,$aLevels) ) echo 'checked="checked" '; ?>/>
                <label for="pro[ppv_rcp_levels][<?php echo $objLevel->id ?>]"><?php echo $objLevel->name; if( $objLevel->status == 'inactive' ) echo ' (inactive)'; ?></label>
              </li>
            <?php endforeach; ?>
            </ul>
            <p class="description">Select which Restrict Content Pro subscription levels should be used for Pay Per View.</p>
          </td>
        </tr>
        <tr>
          <td style="width: 250px"><label for="pro[ppv_title]">Signup form title:</label></td>
          <td>
            <input type="text" size="40" name="pro[ppv_title]" id="pro[ppv_title]" value="<?php if( isset($fv_fp->conf['pro']['ppv_title']) && strlen(trim($fv_fp->conf['pro']['ppv_title'])) ) echo esc_attr( trim($fv_fp->conf['pro']['ppv_title']) ); ?>" />
          </td>
        </tr>
        <tr>
          <td style="width: 250px"><label for="pro[ppv_description]">Signup form description:</label></td>
          <td>
            <input type="text" size="40" name="pro[ppv_description]" id="pro[ppv_description]" value="<?php if( isset($fv_fp->conf['pro']['ppv_description']) && strlen(trim($fv_fp->conf['pro']['ppv_description'])) ) echo esc_attr( trim($fv_fp->conf['pro']['ppv_description']) ); ?>" />
          </td>
        </tr>
        <tr>
          <td colspan="4">
            <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          </td>
        </tr>
      <?php elseif( class_exists('RCP_Member') ) : ?>
        <tr>
          <td colspan="2">
            <p><strong>Error:</strong> Core Restrict Content Pro functions not found, please contact Foliovision for support!</p>
          </td>
        </tr>
      <?php else : ?>
        <tr>
          <td colspan="2">
            <p>
              <?php _e('FV Player Pro integrates with <a href="http://restrictcontentpro.com/pricing/">Restrict Content Pro</a> for pay per view - video rentals. You must install Restrict Content Pro first.', 'fv-player-pro'); ?>
            </p>
          </td>
        </tr>
      <?php endif; ?>
    </table>
    <?php
  }




  function allow_paid_content( $ret ) {
    $aArgs = func_get_args();
    $user_id = $aArgs[1];

    global $post;

    $aCanWatch = get_user_meta( $user_id, 'fv_player_pro_rcp_can_watch', true );
    if( !empty($post->ID) && isset($aCanWatch[$post->ID]) && $aCanWatch[$post->ID] > time() ) {
      return true;
    }

    return $ret;
  }




  function expire_subscription( $ret ) {
    $aArgs = func_get_args();
    $user_id = $aArgs[1];

    $subscription_id = get_user_meta( $user_id, 'rcp_subscription_level', true );
    if( $this->is_pay_per_view($subscription_id) ) {
      return true;
    }

    return $ret;
  }




  function fix_paypal_request( $aData ) {
    $aArgs = func_get_args();
    $objGateway = $aArgs[1];

    $user_id = get_current_user_id();
    if( !$user_id ) {
      return $aData;
    }

    $iPost = get_user_meta( $user_id, 'fv_player_pro_rcp_redirect', true );
    if( $iPost && $this->is_pay_per_view($objGateway->subscription_id) ) {
      $aData['item_name'] = $aData['item_name'].' ('.get_the_title($iPost).')';
      $aData['return'] = add_query_arg( array( 'fv_player_pro_rcp' => true ), get_permalink($iPost) );
      $aData['cancel_return'] = add_query_arg( array( 'fv_player_pro_rcp_cancel' => true ), get_permalink($iPost) );
    }

    return $aData;
  }




  function fix_subscription_request( $aData ) {
    if( $this->is_pay_per_view($aData['subscription_id']) ) {
      $aData['auto_renew'] = false;
    }

    return $aData;
  }




  function get_member_remaining_time() {
    global $post;

    $aCanWatch = get_user_meta( get_current_user_id(), 'fv_player_pro_rcp_can_watch', true );
    $iRemaining = 0;
    if( !empty($post->ID) && $aCanWatch && !empty($aCanWatch[$post->ID]) ) {
      $iRemaining = $aCanWatch[$post->ID] - time();
    }

    return $iRemaining;
  }




  function get_rental_remaining( $iRemaining, $sPhrase = false ) {
    $sRemaining = '';
    if( $iRemaining > 3600 ) {
      $sRemaining = floor($iRemaining/3600).' hours';
    } else if( $iRemaining > 60 ) {
      $sRemaining = floor($iRemaining/60).' minutes';
    } else if( $iRemaining > 0 ) {
      $sRemaining = $iRemaining.' seconds';
    } else {
      $sRemaining = 'Your access to the full video has expired.';
    }

    if( $sPhrase && $iRemaining > 0 ) {
      $sRemaining = sprintf( $sPhrase, $sRemaining );
    }

    return $sRemaining;
  }




  function get_subscription_levels() {
    global $fv_fp;
    $aLevels = isset($fv_fp->conf['pro']['ppv_rcp_levels']) && is_array($fv_fp->conf['pro']['ppv_rcp_levels']) ? $fv_fp->conf['pro']['ppv_rcp_levels'] : array();
    $aLevelsExisting = array();

    // TODO: This seems to be a legacy function
    if( count($aLevels) && function_exists('rcp_get_membership_levels') ) {
      foreach( rcp_get_membership_levels() AS $objLevel ) {
        if( in_array($objLevel->id,$aLevels) && $objLevel->status != 'inactive' ) {
          $aLevelsExisting[] = $objLevel->id;
        }
      }
    }

    return $aLevelsExisting;
  }




  function init() {
    if( class_exists('RCP_Member') ) {

      global $fv_fp;
      if( empty($fv_fp->conf['pro']['ppv_title']) ) {
        $fv_fp->conf['pro']['ppv_title'] = 'Rent this video';
      }
      if( empty($fv_fp->conf['pro']['ppv_description']) ) {
        $fv_fp->conf['pro']['ppv_description'] = 'This is just a short excerpt from the full video. <a href="#">Signup here</a> to watch it in full length.';
      }
      if( empty($fv_fp->conf['pro']['ppv_rcp_levels']) ) {
        $fv_fp->conf['pro']['ppv_rcp_levels'] = array();
      }


      //  todo: watch out for multiple forms per page, this won't work without it!
      //add_filter( 'fv_flowplayer_popup_html', array( $this, 'video_rental_popup' ) );

      add_action( 'init', array( $this, 'process__cancel' ) );

      //  show either a note telling user how much time he has left to watch the video, or if the payment is pending or just a registration form below the video
      if( $this->get_subscription_levels() ) {
        add_filter( 'the_content', array( $this, 'member_content' ), 1 );
      }

      add_action( 'rcp_after_register_form_fields', array( $this, 'register_fields' ) );  //  todo: should also uncheck the renewal checkbox for the 24 hour payment, for now it's disabled entirely

      add_action( 'rcp_form_processing', array( $this, 'process__register' ), 10, 2 );

      //  because the membership is cancelled instantly we need to revive the access to the purchases posts here
      add_filter( 'rcp_is_active', array( $this, 'allow_paid_content' ), 10, 2 );

      //  users with out special subscription level are always expired in order to not be regarded as active and have access to everything
      //add_filter( 'rcp_member_is_expired', array( $this, 'expire_subscription' ), 10, 2 );  //  note: probably not needed since now we delete the membership

      //  if user purchases the right kind of subscription its the video rental, so we process the custom registration fields here and cancell the membership silently
      add_action( 'rcp_member_post_renew', array( $this, 'process__video_rent' ), 10, 3 );

      //  fix return URLs PayPal
      add_filter( 'rcp_paypal_args', array( $this, 'fix_paypal_request' ), 10, 2 );
      //  make sure pay per view doesn't get autorenew
      add_filter( 'rcp_subscription_data', array( $this, 'fix_subscription_request' ), 10, 2 );

      add_shortcode( 'fv_player_rentals', array( $this, 'show_player_rentals' ) );


    }

  }




  function is_pay_per_view( $subscription_id ) {
    global $fv_fp;
    return in_array( $subscription_id, $fv_fp->conf['pro']['ppv_rcp_levels'] );
  }




  function member_content( $content ) {

    $user_id = get_current_user_id();
    if( !class_exists('RCP_Member') ) {
      return $content;
    }

    remove_filter( 'rcp_registration_header_logged_in', array( $this, 'register_form_header' ) );
    remove_filter( 'rcp_registration_header_logged_out', array( $this, 'register_form_header' ) );

    $bHasAccess = false;
    if( $this->get_member_remaining_time() > 0 ) {
      if( strpos($content,'[is_paid') !== false ) {  //  todo: option for which subscription level should be used for video rentals
        $bHasAccess = true;
        $content = preg_replace_callback( '~\[is_paid\][\s\S]+\[/is_paid\]~', array( $this, 'member_content_callback' ), $content );
      }
    } else if( $this->get_member_remaining_time() < 0 ) {
      $content = preg_replace_callback( '~\[is_not_paid\][\s\S]+\[/is_not_paid\]~', array( $this, 'member_content_callback' ), $content );
    }

    global $post;
    $aWannaWatch = get_user_meta( $user_id, 'fv_player_pro_rcp_wanna_watch', true );
    if( isset($_GET['fv_player_pro_rcp']) && !$bHasAccess && $user_id && is_array($aWannaWatch) && isset($aWannaWatch[$post->ID]) ) {
      if( time() - $aWannaWatch[$post->ID] > 300 ) {
        echo '<p class="fv_player_pro_rcp_remaining">The payment verification is taking too long, please contact us for assistance.</p>';
      } else {
        echo '<p class="fv_player_pro_rcp_remaining">Payment is being verified, please reload this page again in a minute.</p>';
      }

    } else if( !$bHasAccess && stripos($content,'[register_form') === false ) {
      $content = str_replace( '[/is_not_paid]','[register_form][/is_not_paid]',$content );

      add_filter( 'rcp_registration_header_logged_in', array( $this, 'register_form_header' ) );
      add_filter( 'rcp_registration_header_logged_out', array( $this, 'register_form_header' ) );

      add_action( 'wp_footer', array( $this, 'scripts' ) );
    } else if( !$bHasAccess && stripos($content,'[register_form') !== false && stripos($content,'[fvplayer') !== false ) {

      add_filter( 'rcp_registration_header_logged_in', array( $this, 'register_form_header' ) );
      add_filter( 'rcp_registration_header_logged_out', array( $this, 'register_form_header' ) );
      add_action( 'wp_footer', array( $this, 'scripts' ) );
    }

    add_action( 'wp_footer', array( $this, 'script_form_hide_renew' ) );

    return $content;
  }




  function member_content_callback($aHTML) {
    global $post;

    $iRemaining = $this->get_member_remaining_time();
    $aHTML[0] = str_replace( '[fvplayer', '<p class="fv_player_pro_rcp_remaining">'.$this->get_rental_remaining( $iRemaining,'You still have %s to watch this video!' ).'</p>'."\n\n".'[fvplayer', $aHTML[0] );
    return $aHTML[0];
  }




  function process__cancel() {
    $user_id = get_current_user_id();
    if( $user_id && isset($_GET['fv_player_pro_rcp_cancel']) ) {
      $iPost = get_user_meta( $user_id, 'fv_player_pro_rcp_redirect', true );

      $aWannaWatch = get_user_meta($user_id, 'fv_player_pro_rcp_wanna_watch', true);
      if( !$aWannaWatch ) $aWannaWatch = array();
      unset($aWannaWatch[$iPost]);

      update_user_meta($user_id, 'fv_player_pro_rcp_wanna_watch',$aWannaWatch);
      delete_user_meta($user_id, 'fv_player_pro_rcp_redirect');
    }
  }




  function process__register( $aPost ) {
    $aArgs = func_get_args();
    $user_id = $aArgs[1];

    if( $this->is_pay_per_view($aPost['rcp_level']) ) {
      $aWannaWatch = get_user_meta($user_id, 'fv_player_pro_rcp_wanna_watch', true);
      if( !$aWannaWatch ) $aWannaWatch = array();

      $aWannaWatch[$aPost['fv_player_rcp_post_id']] = time();
      update_user_meta($user_id, 'fv_player_pro_rcp_wanna_watch',$aWannaWatch);
    }

    update_user_meta($user_id, 'fv_player_pro_rcp_redirect',$aPost['fv_player_rcp_post_id']);

    //file_put_contents(ABSPATH.'fv-player-pro-rcp.log',date('r')." process__register:\n".var_export($aArgs,true)."\n---\n\n",FILE_APPEND);
  }




  function process__video_rent( $user_id ) {
    $aArgs = func_get_args();

    $subscription_id = get_user_meta( $user_id, 'rcp_subscription_level', true );
    if( !$this->is_pay_per_view($subscription_id) ) {
      return;
    }

    //  cancel the subscription - this worked fine, but the membership was showing as cancelled both to admin and the user
    /*remove_action( 'rcp_set_status', 'rcp_email_on_cancellation', 10, 2 );
    $aArgs[2]->set_status( 'cancelled' ); //  todo: what about the "Your Membership" section?
    add_action( 'rcp_set_status', 'rcp_email_on_cancellation', 10, 2 );*/

    //  delete the membership info
    delete_user_meta( $user_id, 'rcp_status' );
    delete_user_meta( $user_id, 'rcp_subscription_key' );
    delete_user_meta( $user_id, 'rcp_subscription_level' );
    delete_user_meta( $user_id, 'rcp_expiration' );

    //  figure out which post is being purchased
    $aWannaWatch = get_user_meta( $user_id, 'fv_player_pro_rcp_wanna_watch', true );
    if( !$aWannaWatch ) {
      //file_put_contents(ABSPATH.'fv-player-pro-rcp.log',date('r')." fv_player_pro_rcp_wanna_watch missing:\n".var_export($aArgs,true)."\n".var_export($aWannaWatch,true)."\n---\n\n",FILE_APPEND);
      return;
    }

    $iPost = array_pop( array_keys($aWannaWatch) );
    unset($aWannaWatch[$iPost]);
    update_user_meta( $user_id, 'fv_player_pro_rcp_wanna_watch', $aWannaWatch );

    //  store
    $aCanWatch = get_user_meta($user_id, 'fv_player_pro_rcp_can_watch', true);
    if( !$aCanWatch ) $aCanWatch = array();

    $aRentals = get_user_meta($user_id, 'fv_player_pro_rcp_rentals', true);
    if( !$aRentals ) $aRentals = array();

    $iDuration = 25*3600;

    if( function_exists('rcp_get_subscription_details') ) {
      $objSubscription = rcp_get_subscription_details($subscription_id);
      if( $objSubscription->duration == 0 ) { //  unlimited
        $iDuration = 365*24*3600*100;

      } else {
        $iDuration = $objSubscription->duration * 24 * 3600;
        if( $objSubscription->duration_unit == 'month' ) {
          $iDuration = $iDuration * 31;
        } else if( $objSubscription->duration_unit == 'year' ) {
          $iDuration = $iDuration * 365;
        }
        $iDuration += 3600; //safety period

      }
    }

    $aCanWatch[$iPost] = time() + $iDuration;
    update_user_meta($user_id, 'fv_player_pro_rcp_can_watch',$aCanWatch);

    $aRentals[$iPost] = time();
    update_user_meta($user_id, 'fv_player_pro_rcp_rentals',$aRentals);

    rcp_add_member_note( $user_id, 'FV Player Pro: access to video in post '.$iPost.' granted!' );

    //file_put_contents(ABSPATH.'fv-player-pro-rcp.log',date('r')." demote:\n".var_export($aArgs,true)."\n".var_export($iPost,true)."\n---\n\n",FILE_APPEND);
  }




  function rcp_fix_reports( $sql ) {  //  since we adjust the subscription name to contain the movie name, we need to fix the reports SQL to show the video rentals
    if( stripos($sql,'rcp_payments WHERE') ) {
      $sql = preg_replace( "~rcp_payments WHERE `subscription`= '(.*?)' AND~", "rcp_payments WHERE `subscription` LIKE '$1%' AND", $sql );
    }
    return $sql;
  }




  function register_fields() {
    echo "<input type='hidden' name='fv_player_rcp_post_id' value='".get_the_ID()."' />\n";
  }




  function register_form_header( $title ) {
    global $fv_fp;
    return $fv_fp->conf['pro']['ppv_title'].'</h3><p class="fv_player_pro_rcp_signup">'.$fv_fp->conf['pro']['ppv_description'].'</p><h3 style="display: none">';  // todo: this is dirty
  }




  function scripts() {
    if( !is_single() ) return;

    ?>
    <script>
    jQuery(document).on('click','.fv_player_pro_rcp_signup a', function(e) {
      jQuery(this).parent().siblings('#rcp_registration_form').slideToggle();
      e.preventDefault();
    });
    </script>

    <style>
    form#rcp_registration_form {
      display: none;
    }
    </style>

    <?php
  }




  function script_form_hide_renew() {
    if( !is_single() ) return;

    global $fv_fp;
    ?>
    <script>
    var fv_player_pro_rcp_ppv = <?php echo json_encode($fv_fp->conf['pro']['ppv_rcp_levels']); ?>;
    jQuery( function($) {
      function fv_player_pro_rcp_check_renew(args) {
        var fv_player_pro_rcp_ppv_unchecked = 0;
        for( var i in fv_player_pro_rcp_ppv ) {
          if( $('#rcp_subscription_level_'+fv_player_pro_rcp_ppv[i]+':checked').length ) {
            fv_player_pro_rcp_ppv_unchecked = 1;
            $('#rcp_auto_renew').prop("checked",false).prop('disabled',true);
          }
          if( $('#rcp_subscription_levels').length == 0 ) {
            if( $('[name=rcp_level]').val() == fv_player_pro_rcp_ppv[i] ) {
              fv_player_pro_rcp_ppv_unchecked = 1;
              $('#rcp_auto_renew').prop("checked",false).prop('disabled',true);
            }
          }
        }

        if( fv_player_pro_rcp_ppv_unchecked == 0 ) {
          fv_player_pro_rcp_ppv_unchecked = 2;
          $('#rcp_auto_renew').prop("checked",true).prop('disabled',false);
        }

      }
      fv_player_pro_rcp_check_renew();
      $('input[type=radio]').on('click', fv_player_pro_rcp_check_renew);
    });
    </script>
    <?php
  }




  function show_player_rentals() {
    $user_id = get_current_user_id();
    if( !$user_id ) return;

    $aRentals = get_user_meta( $user_id, 'fv_player_pro_rcp_can_watch', true );
    if( !$aRentals ) {
      return 'No video rentals found!';
    }

    $aRentalsMeta = get_user_meta( $user_id, 'fv_player_pro_rcp_rentals', true );


    $sHTML = '<h2>Video rentals</h2><table class="rcp-table" id="rcp-payment-history"><thead><tr><th>Video</th><th>Date</th><th>Time Remaining</th><th>Actions</th></tr></thead><tbody>';
    $aRentals = array_reverse( $aRentals, true );
    foreach( $aRentals AS $post_id => $iExpiration ) {
      $sHTML .= '<tr>';
      $sHTML .= '<td>'.get_the_title($post_id).'</td>';
      $sHTML .= '<td>'.( !empty($aRentalsMeta[$post_id]) ? date('F j, Y',$aRentalsMeta[$post_id]) : '' ).'</td>';
      $sHTML .= '<td>'.$this->get_rental_remaining($iExpiration - time()).'</td>';
      $sHTML .= '<td><a href="'.get_permalink($post_id).'">'.( time() < $iExpiration ? 'Watch' : 'Rent again').'</a></td>';
      $sHTML .= '</tr>';
    }


    $sHTML .= '</tbody></table>';

    return $sHTML;
  }




  function video_rental_popup( $html ) {
    //  todo: fix the message outputted by register form!
    //  todo: fix keyboard binding - goes to Flowplayer!
    $html = do_shortcode( '[register_form]' );
    return $html; //  todo: make sure the recurring checkbox doesn't check!
  }




}

$FV_Player_Pro_RCP_Bridge = new FV_Player_Pro_RCP_Bridge();
