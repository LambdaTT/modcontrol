<?php

namespace Modcontrol\Migrations;

use SplitPHP\DbMigrations\Migration;
use SplitPHP\Database\DbVocab;

class AddIsMainappColumnToTableModule extends Migration
{
  public function apply()
  {
    $this->Table('MDC_MODULE')
      ->string('do_is_mainapp', 1)->setDefaultValue('N');
  }
}
