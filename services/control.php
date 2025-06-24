<?php

namespace Modcontrol\Services;

use SplitPHP\Service;

class ModControlService extends Service
{
  /* List all modules related to a profile, based on parameters. */

  public function list($params = [])
  {
    return $this->getDao('MDC_MODULE')
      ->bindParams($params)
      ->find(
        "SELECT 
          ds_title, 
          dt_created, 
          id_mdc_module 
        FROM `MDC_MODULE`"
      );
  }

  public function get($params = [])
  {
    return $this->getDao('MDC_MODULE')
      ->bindParams($params)
      ->first();
  }

  public function getModuleEntities($modParams)
  {
    return $this->getDao('MDC_MODULE_ENTITY')
      ->bindParams($modParams, 'modFilters')
      ->find(
        "SELECT 
            ent.*,
            mod.ds_title AS modTitle 
          FROM `MDC_MODULE_ENTITY` ent
          JOIN (
            SELECT * FROM `MDC_MODULE` 
            #modFilters#
          ) mod ON ent.id_mdc_module = mod.id_mdc_module"
      );
  }
}
