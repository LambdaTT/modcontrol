<?php

namespace Modcontrol\Services;

use SplitPHP\Service;

class ModControlService extends Service
{
  /**
   * Get a summary of all modules with their entities.
   * @param array $params
   * @return array
   */
  public function summary($params = [])
  {
    $result = [];

    $this->getDao('MDC_MODULE')
      ->bindParams($params)
      ->fetch(
        function ($row) use (&$result) {
          if (!array_key_exists($row['id_mdc_module'], $result)) {
            $result[$row['id_mdc_module']] = [
              'id_mdc_module' => $row['id_mdc_module'],
              'ds_title' => $row['ds_title'],
              'dt_created' => $row['dt_created'],
              'entities' => []
            ];
          }

          $result[$row['id_mdc_module']]['entities'][] = [
            'ds_entity_name' => $row['ds_entity_name'],
            'ds_entity_label' => $row['ds_entity_label']
          ];
        },
        "SELECT 
            mod.id_mdc_module, 
            mod.ds_title, 
            mod.dt_created, 
            ent.ds_entity_name,
            ent.ds_entity_label
          FROM `MDC_MODULE` mod
          LEFT JOIN `MDC_MODULE_ENTITY` ent ON mod.id_mdc_module = ent.id_mdc_module"
      );

    return array_values($result);
  }

  /**
   * List all modules with basic details.
   * @param array $params
   * @return array
   */
  public function list($params = [])
  {
    return $this->getDao('MDC_MODULE')
      ->bindParams($params)
      ->find(
        "SELECT 
            m.ds_title, 
            m.ds_key, 
            m.dt_created, 
            m.id_mdc_module,
            COUNT(ent.id_mdc_module_entity) AS numEntities
          FROM `MDC_MODULE` m
          LEFT JOIN `MDC_MODULE_ENTITY` ent ON m.id_mdc_module = ent.id_mdc_module
          GROUP BY
            m.id_mdc_module,
            m.ds_title,
            m.dt_created"
      );
  }

  /**
   * Get all the details of a specific module by its parameters.
   * @param array $params
   * @return array|null
   */
  public function get($params = [])
  {
    return $this->getDao('MDC_MODULE')
      ->bindParams($params)
      ->first();
  }

  public function moduleExists(string $uTag)
  {
    return empty($this->get(['ds_utag' => $uTag]));
  }

  /**
   * Get all entities associated with a module based on the provided parameters.
   * @param array $modParams
   * @return array
   */
  public function getModuleEntities(array $params = [], array $modParams = [])
  {
    return $this->getDao('MDC_MODULE_ENTITY')
      ->bindParams($modParams, 'modFilters')
      ->bindParams($params)
      ->find(
        "SELECT 
            ent.*,
            m.ds_title AS modTitle 
          FROM `MDC_MODULE_ENTITY` ent
          JOIN (
            SELECT * FROM `MDC_MODULE` 
            #modFilters#
          ) m ON ent.id_mdc_module = m.id_mdc_module"
      );
  }
}
