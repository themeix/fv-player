<?php

  $args = array(
      'credentials' => array(
      'key' => $fv_fp->conf['pro']['elastic_key'],
      'secret' => $fv_fp->conf['pro']['elastic_secret'],
    ),
    'region' => $fv_fp->conf['pro']['elastic_region'],
    'version' => '2014-11-01'
  );
  $kms = \Aws\Kms\KmsClient::factory($args);
  try{
    $result = $kms->decrypt(array(
        'EncryptionContext' => array('service' => 'elastictranscoder.amazonaws.com'),
        'CiphertextBlob' => base64_decode($_POST['cryptic']),
    ));
  }catch(\Aws\Kms\Exception\KmsException $e){
    echo $e->getMessage();
    die();
  }
