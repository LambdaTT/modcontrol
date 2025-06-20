<?php

namespace Modcontrol\Services;

use SplitPHP\Service;

class ModControlService extends Service
{
  /* List all modules related to a profile, based on parameters. */

  public function list($params = [])
  {
    return $this->getDao('APM_MODULE')
      ->bindParams($params)
      ->find(
        "SELECT 
          ds_title, 
          dt_created, 
          id_apm_module 
        FROM `APM_MODULE`"
      );
  }

  public function get($params = [])
  {
    return $this->getDao('APM_MODULE')
      ->bindParams($params)
      ->first();
  }
}
