<?php

namespace Modcontrol\Migrations;

use SplitPHP\DbMigrations\Migration;
use SplitPHP\Database\DbVocab;

class AddUtagFieldToModuleTable extends Migration
{
  public function apply()
  {
    $this->Table('MDC_MODULE')
      ->string('ds_utag', 10)
      ->nullable()
      ->setDefaultValue(null)
      ->Index('KEY', DbVocab::IDX_UNIQUE)->onColumn('ds_utag');
  }
}
