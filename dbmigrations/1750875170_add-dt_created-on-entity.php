<?php

namespace Modcontrol\Migrations;

use SplitPHP\DbMigrations\Migration;
use SplitPHP\Database\DbVocab;

class AddDtCreatedOnEntity extends Migration
{
  public function apply()
  {
    $this->Table('MDC_MODULE_ENTITY')
      ->datetime('dt_created')->setDefaultValue(DbVocab::SQL_CURTIMESTAMP());
  }
}
