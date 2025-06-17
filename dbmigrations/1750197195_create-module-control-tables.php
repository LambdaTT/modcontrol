<?php

namespace Modcontrol\Migrations;

use SplitPHP\DbMigrations\Migration;
use SplitPHP\Database\DbVocab;

class CreateModuleControlTables extends Migration
{
  public function apply()
  {
    $this->Table('MDC_MODULE')
      ->id('id_mdc_module')
      ->string('ds_key', 17)
      ->datetime('dt_created')->setDefaultValue(DbVocab::SQL_CURTIMESTAMP())
      ->datetime('dt_updated')->nullable()->setDefaultValue(null)
      ->string('ds_title', 100)
      ->Index('KEY', DbVocab::IDX_UNIQUE)->onColumn('ds_key');

    $this->Table('MDC_MODULE_ENTITY')
      ->id('id_mdc_module_entity')
      ->int('id_mdc_module')
      ->string('ds_entity_name', 60)
      ->string('ds_entity_label', 60)
      ->Foreign('id_mdc_module')->references('id_mdc_module')->atTable('MDC_MODULE')->onUpdate(DbVocab::FKACTION_CASCADE)->onDelete(DbVocab::FKACTION_CASCADE);
  }
}
