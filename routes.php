<?php
namespace application\routes\api;

use \engine\WebService;
class Applicationmodules extends WebService
{
  public function init()
  {
    /////////////////
    // MODULE ENDPOINTS:
    /////////////////

    $this->addEndpoint('GET', '/v1/module/?moduleId?', function($params){
      // Auth user login:
      if (!$this->getService('iam/session')->authenticate()) return $this->response->withStatus(401);
  
      $data = $this->getService('application_module')->get(['id_core_module' => $params['moduleId']]);
      if (empty($data)) return $this->response->withStatus(404);
  
      return $this->response->withData($data);
    });
    $this->addEndpoint('GET', '/v1/module', function($params){
      // Auth user login:
      if (!$this->getService('iam/session')->authenticate()) return $this->response->withStatus(401);
  
      return $this->response->withData($this->getService('application_module')->list($params));
    });
  }
}
