<?php

if( !class_exists('FV_Player_Pro_Cloudflare_Ip') ) :

class FV_Player_Pro_Cloudflare_Ip {
  function __construct() {
    add_action( 'fv_player_pro_update_cloudflare_ips', array( $this, 'get_latest_cloudflare_ips' ) );
    add_action( 'admin_init', array( $this, 'cron_init' ) );
  }

  /**
   * Check if a given ip is in a network.
   * @param  string $ip    IP to check in IPV4 format eg. 127.0.0.1
   * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
   * @return boolean true if the ip is in this range / false if not.
   */
  function ipv4_in_range( $ip, $range ) {
    if ( strpos( $range, '/' ) == false ) {
      $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24
    list( $range, $netmask ) = explode( '/', $range, 2 );
    $range_decimal = ip2long( $range );
    $ip_decimal = ip2long( $ip );
    $wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
    $netmask_decimal = ~ $wildcard_decimal;
    return ( ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal ) );
  }

  /**
   * Determine whether the IPV6 address is within range.
   * @param string $ip is the IPV6 address in decimal format to check if its within the IP range created by the cloudflare IPV6 address.
   * @param string $range_ip IPV6 netmask
   * @return boolean true if the IPV6 address, $ip,  is within the range from $range_ip.  False otherwise.
   */
  function ipv6_in_range($ip, $range_ip) {
    $pieces = explode ("/", $range_ip, 2);
    $left_piece = $pieces[0];
    $right_piece = $pieces[1];

    // Extract out the main IP pieces
    $ip_pieces = explode("::", $left_piece, 2);
    $main_ip_piece = $ip_pieces[0];
    $last_ip_piece = $ip_pieces[1];

    // Pad out the shorthand entries.
    $main_ip_pieces = explode(":", $main_ip_piece);
    foreach($main_ip_pieces as $key=>$val) {
      $main_ip_pieces[$key] = str_pad($main_ip_pieces[$key], 4, "0", STR_PAD_LEFT);
    }

    // Create the first and last pieces that will denote the IPV6 range.
    $first = $main_ip_pieces;
    $last = $main_ip_pieces;

    // Check to see if the last IP block (part after ::) is set
    $last_piece = "";
    $size = count($main_ip_pieces);
    if (trim($last_ip_piece) != "") {
      $last_piece = str_pad($last_ip_piece, 4, "0", STR_PAD_LEFT);
  
      // Build the full form of the IPV6 address considering the last IP block set
      for ($i = $size; $i < 7; $i++) {
        $first[$i] = "0000";
        $last[$i] = "ffff";
      }
      $main_ip_pieces[7] = $last_piece;
    }
    else {
      // Build the full form of the IPV6 address
      for ($i = $size; $i < 8; $i++) {
        $first[$i] = "0000";
        $last[$i] = "ffff";
      }
    }

    // Rebuild the final long form IPV6 address
    $first = $this->ip2long6(implode(":", $first));
    $last = $this->ip2long6(implode(":", $last));
    $in_range = ($ip >= $first && $ip <= $last);

    return $in_range;
  }

  // Converts a string containing an (IPv6) into a long integer
  function ip2long6($ip) {
    if (substr_count($ip, '::')) {
      $ip = str_replace('::', str_repeat(':0000', 8 - substr_count($ip, ':')) . ':', $ip); 
    }

    $ip = explode(':', $ip);
    $r_ip = ''; 
    foreach ($ip as $v) {
      $r_ip .= str_pad(base_convert($v, 16, 2), 16, 0, STR_PAD_LEFT); 
    }

    return base_convert($r_ip, 2, 10); 
  }

  // Get the ipv6 full format and return it as a decimal value.
  function get_ipv6_full($ip) {
    $pieces = explode ("/", $ip, 2);
    $left_piece = $pieces[0];
    $right_piece = $pieces[1];

    // Extract out the main IP pieces
    $ip_pieces = explode("::", $left_piece, 2);
    $main_ip_piece = $ip_pieces[0];
    $last_ip_piece = $ip_pieces[1];

    // Pad out the shorthand entries.
    $main_ip_pieces = explode(":", $main_ip_piece);
    foreach($main_ip_pieces as $key=>$val) {
      $main_ip_pieces[$key] = str_pad($main_ip_pieces[$key], 4, "0", STR_PAD_LEFT);
    }

    // Check to see if the last IP block (part after ::) is set
    $last_piece = "";
    $size = count($main_ip_pieces);
    if (trim($last_ip_piece) != "") {
      $last_piece = str_pad($last_ip_piece, 4, "0", STR_PAD_LEFT);
  
      // Build the full form of the IPV6 address considering the last IP block set
      for ($i = $size; $i < 7; $i++) {
        $main_ip_pieces[$i] = "0000";
      }
      $main_ip_pieces[7] = $last_piece;
    }
    else {
      // Build the full form of the IPV6 address
      for ($i = $size; $i < 8; $i++) {
        $main_ip_pieces[$i] = "0000";
      }
    }

    // Rebuild the final long form IPV6 address
    $final_ip = implode(":", $main_ip_pieces);

    return $this->ip2long6($final_ip);
  }

  public function http_get_cloudflare_ips() {
    $ips = '';
    $response = wp_remote_get( 'https://api.cloudflare.com/client/v4/ips' );

    if( !is_wp_error($response) ) {
      $ips = wp_remote_retrieve_body($response);
      $decoded = json_decode( $ips, true );
      if( !$decoded['success'] ) {
        $ips = '';
      }
    }

    return $ips;
  }

  public function get_latest_cloudflare_ips() {
    $saved_data = get_option('fv_player_pro_cf_ips', array('cloudflare_ips_expire' => 0, 'ips' => '')); // store old ips if fail to retrieve new ips
    
    if( time() > $saved_data['cloudflare_ips_expire'] ) { // update only if expired
      $ips = $this->http_get_cloudflare_ips();
      if(!empty($ips)) {
        update_option( 'fv_player_pro_cf_ips', array('cloudflare_ips_expire' => time() + 86400 , 'ips' => $ips ), false ); // if success store for 1 day
        return $ips;
      } else {
        update_option( 'fv_player_pro_cf_ips', array('cloudflare_ips_expire' => time() + 60, 'ips' => $saved_data['ips'] ), false );
      }
    }

    return $saved_data['ips'];
  }

  function verify_cf_connecting_ip() {
    $cf_ips =  $this->get_latest_cloudflare_ips();

    if( !empty($cf_ips) ) {
      $cf_ips = json_decode( $cf_ips, true );
      if( $cf_ips['success'] ) {
        if (filter_var($_SERVER["REMOTE_ADDR"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { // check if is ipv6
          // iterate over ipv6
          $ipv6 = $this->get_ipv6_full($_SERVER["REMOTE_ADDR"]);
          foreach( $cf_ips['result']['ipv6_cidrs'] as $k => $ip ) {
            if( $this->ipv6_in_range( $ipv6, $ip ) ) { // if passed , then ipv6 is from cf
              return $_SERVER["HTTP_CF_CONNECTING_IP"];
            }
          }
        } else if (filter_var($_SERVER["REMOTE_ADDR"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { // check if is ipv4
          // iterate over ipv4
          foreach( $cf_ips['result']['ipv4_cidrs'] as $k => $ip ) {
            if( $this->ipv4_in_range( $_SERVER["REMOTE_ADDR"], $ip ) ) { // if passed , then ipv4 is from cf
              return $_SERVER["HTTP_CF_CONNECTING_IP"];
            }
          }
        }
      }
    }

    return $_SERVER['REMOTE_ADDR']; // ip is not from cloudlare then use REMOTE_ADDR
  }

  public function cron_init() {
    if ( !wp_next_scheduled( 'fv_player_pro_update_cloudflare_ips' ) && FV_Player_Pro()->_get_option( array('pro', 'cf_ips_cron')) ) {
      wp_schedule_event( time(), 'hourly', 'fv_player_pro_update_cloudflare_ips' );
    } else if( wp_next_scheduled( 'fv_player_pro_update_cloudflare_ips' ) && !FV_Player_Pro()->_get_option( array('pro', 'cf_ips_cron')) ) {
      wp_unschedule_hook( 'fv_player_pro_update_cloudflare_ips' );
    }
  }

}

global $FV_Player_Pro_Cloudflare_Ip;
$FV_Player_Pro_Cloudflare_Ip = new FV_Player_Pro_Cloudflare_Ip;

endif;