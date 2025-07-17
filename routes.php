<?php

namespace Modcontrol\Routes;

use SplitPHP\Request;
use SplitPHP\WebService;

class ModControl extends WebService
{
  public function init(): void
  {
    /////////////////
    // MODULE ENDPOINTS:
    /////////////////

    $this->addEndpoint('GET', '/v1/module/?moduleKey?', function (Request $r) {
      // Auth user login:
      if (!$this->getService('iam/session')->authenticate()) return $this->response->withStatus(401);

      $params = [
        'ds_key' => $r->getRoute()->params['moduleKey']
      ];

      $data = $this->getService('modcontrol/control')->get($params);
      if (empty($data)) return $this->response->withStatus(404);

      return $this->response->withData($data);
    });

    $this->addEndpoint('GET', '/v1/module', function ($params) {
      // Auth user login:
      if (!$this->getService('iam/session')->authenticate()) return $this->response->withStatus(401);

      return $this->response->withData($this->getService('modcontrol/control')->list($params));
    });
  }
}
