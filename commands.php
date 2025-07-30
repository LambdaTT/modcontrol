<?php

namespace Modcontrol\Commands;

use SplitPHP\AppLoader;
use SplitPHP\Cli;
use SplitPHP\Database\Dao;
use SplitPHP\Database\Database;
use SplitPHP\ModLoader;
use SplitPHP\Utils;
use SplitPHP\ObjLoader;

class Commands extends Cli
{
  public function init(): void
  {
    $this->addCommand('modules:list', function ($args) {
      $getRows = function ($params) {
        return $this->getService('modcontrol/control')->list($params);
      };

      $columns = [
        'id_mdc_module'           => 'ID',
        'dt_created'              => 'Created At',
        'ds_title'                => 'Module',
        'numEntities'             => 'Entities',
      ];

      $this->getService('utils/misc')->printDataTable("Modules List", $getRows, $columns, $args);
    });

    $this->addCommand('modules:create', function () {
      Utils::printLn("\033[36m>> 👋 Welcome to the Modules Create Command!\033[0m");
      Utils::printLn("  This command will help you add a new module.");
      Utils::printLn();
      Utils::printLn("  Please follow the prompts to define your module informations.");
      Utils::printLn();
      Utils::printLn("  \033[36m>> New Module:\033[0m");
      Utils::printLn("------------------------------------------------------");

      $module = $this->getService('utils/clihelper')->inputForm([
        'ds_title' => [
          'label' => 'Module Title',
          'required' => true,
          'length' => 100,
        ]
      ]);

      $module->ds_key = 'mdc-' . uniqid();

      $record = $this->getDao('MDC_MODULE')
        ->insert($module);

      Utils::printLn("  \033[92m>> ✅ Module added successfully!\033[0m");
      foreach ($record as $key => $value) {
        Utils::printLn("    -> {$key}: {$value}");
      }
    });

    $this->addCommand('modules:remove', function () {
      Utils::printLn("\033[36m>>> 👋 Welcome to the Module Removal Command!\033[0m");
      Utils::printLn();
      $moduleName = $this->setModuleName();

      $this->getDao('MDC_MODULE')
        ->filter('ds_title')->equalsTo($moduleName)
        ->delete();
      Utils::printLn("  \033[92m>>> ✅ Module \033[34m'{$moduleName}'\033[92m removed successfully!\033[0m");
    });

    $this->addCommand('modules:map', function ($args) {
      require_once CORE_PATH . '/database/class.vocab.php';
      require_once CORE_PATH . '/database/' . Database::getRdbmsName() . '/class.sql.php';
      require_once CORE_PATH . '/dbmanager/class.migration.php';

      Utils::printLn("  \033[36m>> 👋 Welcome to the Module Mapping Command!\033[0m");
      echo PHP_EOL;
      sleep(1);

      if (!is_null($moduleName = $args['--module'] ?? null)) {
        if ($moduleName == 'modcontrol') {
          Utils::printLn("  \033[91m>> 💣 The \033[34m'modcontrol'\033[36m module is reserved for this command and cannot be mapped.\033[0m");
          return;
        }
      }

      if (!is_null($ignoreModule = $args['--ignore'] ?? null)) {
        if ($ignoreModule == $moduleName) {
          Utils::printLn("  \033[93m>> ⚠️ The module  \033[34m'{$moduleName}'\033[93m is set to be ignored. Skipping mapping.\033[0m");
          return;
        }
      }

      // For a specific module, we can ask the user to define it:
      if ($moduleName !== null) {
        $moduleName = ucwords($moduleName);
        $module = $this->getDao('MDC_MODULE')
          ->filter('ds_title')->equalsTo($moduleName)
          ->first();
        if (!$module) {
          $this->getDao('MDC_MODULE')
            ->insert([
              'ds_key' => 'mdc-' . uniqid(),
              'ds_title' => $moduleName,
            ]);

          Dao::flush();
        }
      }

      // Map all modules and their entities:
      $entities = [];
      $mList = ModLoader::listMigrations($moduleName ?? null);
      // Iterate over modules:
      foreach ($mList as $modName => $mData) {
        $entities = [];
        if ($modName == 'modcontrol' || $modName == $ignoreModule) continue;

        $modName = ucwords($modName);

        Utils::printLn("  \033[36m>> ⏳ Mapping module {$modName}'s entities...\033[0m");
        if (empty($module = $this->getService('modcontrol/control')->get(['ds_title' => $modName]))) {
          $module = $this->getDao('MDC_MODULE')
            ->insert([
              'ds_key' => 'mdc-' . uniqid(),
              'ds_title' => $modName,
            ]);
        }

        // Iterate over module migrations:
        foreach ($mData as $mDataItem) {
          $mobj = ObjLoader::load($mDataItem->filepath);
          $mobj->apply();
          $operations = $mobj->getOperations();

          // Iterate over migration operations:
          foreach ($operations as $op) {
            if ($op->type != 'table') continue;

            $blueprint = $op->blueprint;
            if (array_key_exists($blueprint->getName(), $entities)) continue;

            $entity = [
              'id_mdc_module' => $module->id_mdc_module,
              'ds_entity_name' => $blueprint->getName(),
              'ds_entity_label' => $blueprint->getLabel(),
            ];

            $conflict = $this->getDao('MDC_MODULE_ENTITY')
              ->filter('id_mdc_module')->equalsTo($module->id_mdc_module)
              ->and('ds_entity_name')->equalsTo($entity['ds_entity_name'])
              ->first();

            if ($conflict) {
              continue;
            }

            $entities[$blueprint->getName()] = $this->getDao('MDC_MODULE_ENTITY')
              ->insert($entity);
          } // migration operations

          Dao::flush();
          ObjLoader::unload($mDataItem->filepath);
          unset($mobj, $operations, $op, $blueprint);
        } // module migrations

        if (empty($entities)) {
          Utils::printLn("  \033[93m>> ⚠️  No new entities found in module \033[34m'{$modName}'\033[93m.\033[0m");
          Utils::printLn();
          continue;
        }
        Utils::printLn("  \033[92m>> ✅ Module \033[34m'{$modName}'\033[92m mapped successfully with the following new entities:\033[0m");
        Utils::printLn();
        foreach ($entities as $entity) {
          Utils::printLn("    -> {$entity->ds_entity_name} ({$entity->ds_entity_label})");
        }
        Utils::printLn();
      } // modules

      // Map the main app module:
      Utils::printLn("  \033[36m>> ⏳ Mapping Main App's entities...\033[0m");

      if (empty($appMod = $this->getDao('MDC_MODULE')
        ->filter('do_is_mainapp')->equalsTo('Y')
        ->first())) {
        $appModName = readline("  \033[36m>> Please, define the main app name as a module to be represented in this control (Ex.: 'General'): \033[0m") ?: 'General';

        $appMod = $this->getDao('MDC_MODULE')
          ->insert([
            'ds_key' => 'mdc-' . uniqid(),
            'ds_title' => ucwords($appModName),
            'do_is_mainapp' => 'Y',
          ]);
      }

      $entities = [];

      // Map main app entities:
      $mList = AppLoader::listMigrations();
      foreach ($mList as $mData) {
        $mobj = ObjLoader::load($mData->filepath);
        $mobj->apply();
        $operations = $mobj->getOperations();
        foreach ($operations as $op) {
          if ($op->type != 'table') continue;

          $blueprint = $op->blueprint;
          if (array_key_exists($blueprint->getName(), $entities)) continue;

          $entity = [
            'id_mdc_module' => $appMod->id_mdc_module,
            'ds_entity_name' => $blueprint->getName(),
            'ds_entity_label' => $blueprint->getLabel(),
          ];

          $entities[$blueprint->getName()] = $this->getDao('MDC_MODULE_ENTITY')
            ->insert($entity);
        }

        ObjLoader::unload($mData->filepath);
        unset($mobj, $operations, $op, $blueprint);
      }

      if (empty($entities)) {
        Utils::printLn();
        Utils::printLn("  \033[93m>> ⚠️  No new entities found in \033[34mMain Application\033[93m.\033[0m");
        Utils::printLn();
        return;
      }

      Utils::printLn("  \033[92m>> ✅ Module \033[34m'{$appMod->ds_title}'\033[92m mapped successfully with the following new entities:\033[0m");
      Utils::printLn();
      foreach ($entities as $entity) {
        Utils::printLn("    -> {$entity->ds_entity_name} ({$entity->ds_entity_label})");
      }
      Utils::printLn();
    });

    $this->addCommand('entities:list', function ($args) {
      // Extract and normalize our options
      $moduleName = $this->setModuleName($args);
      $getRows = function ($params) use (&$moduleName) {
        $modParams = [
          'ds_title' => $moduleName,
        ];
        return $this->getService('modcontrol/control')->getModuleEntities($params, $modParams);
      };

      $columns = [
        'id_mdc_module_entity' => 'ID',
        'ds_entity_name'       => 'Entity Name',
        'ds_entity_label'      => 'Entity Label',
        'dt_created'           => 'Created At',
      ];

      $this->getService('utils/misc')->printDataTable("Module Entities List", $getRows, $columns, $args);
    });

    $this->addCommand('entities:add', function ($args) {
      $moduleName = $this->setModuleName($args);
      Utils::printLn("Welcome to the Module Entity Add Command!");
      Utils::printLn("This command will help you add a new entity to the module with name {$moduleName}.");
      Utils::printLn();
      Utils::printLn(" >> Please follow the prompts to define your entity informations.");
      Utils::printLn();
      Utils::printLn("  >> New Entity:");
      Utils::printLn("------------------------------------------------------");

      $moduleId = $this->getDao('MDC_MODULE')
        ->filter('ds_title')->equalsTo($moduleName)
        ->first()
        ->id_mdc_module ?? null;

      if (!$moduleId) {
        Utils::printLn("  >> Module with name '{$moduleName}' not found.");
        return;
      }

      $entity = $this->getService('utils/clihelper')->inputForm([
        'ds_entity_name' => [
          'label' => 'Entity Name',
          'required' => true,
          'length' => 60,
        ],
        'ds_entity_label' => [
          'label' => 'Entity Label',
          'required' => true,
          'length' => 60,
        ]
      ]);

      $entity->id_mdc_module = $moduleId;

      $record = $this->getDao('MDC_MODULE_ENTITY')
        ->insert($entity);

      Utils::printLn("  >> Entity added successfully!");
      foreach ($record as $key => $value) {
        Utils::printLn("    -> {$key}: {$value}");
      }
    });

    $this->addCommand('entities:remove', function () {
      Utils::printLn("Welcome to the Module Entity Removal Command!");
      Utils::printLn();
      Utils::printLn("  >> Please, enter the Entity Name you want to remove: ");
      $moduleName = $this->setModuleName();
      $entityName = $this->getService('utils/misc')->persistentCliInput(
        function ($v) {
          return !empty($v) && is_string($v);
        },
        "Please, enter the Entity Name you want to remove: "
      );

      Utils::printLn("  >> Please confirm you want to remove the entity with name {$entityName}.");
      $confirm = readline("  >> Type 'yes' to confirm: ");
      if (strtolower($confirm) !== 'yes') {
        Utils::printLn("  >> Operation cancelled.");
        return;
      }

      $moduleId = $this->getDao('MDC_MODULE')
        ->filter('ds_title')->equalsTo($moduleName)
        ->first()
        ->id_mdc_module ?? null;
      if (!$moduleId) {
        Utils::printLn("  >> Module with name '{$moduleName}' not found.");
        return;
      }

      $this->getDao('MDC_MODULE_ENTITY')
        ->filter('id_mdc_module')->equalsTo($moduleId)
        ->and('ds_entity_name')->equalsTo($entityName)
        ->delete();
      Utils::printLn("  >> Entity with name {$entityName} removed successfully!");
    });

    // Help command
    $this->addCommand('help', function () {
      /** @var \Utils\Services\CliHelper $helper */
      $helper = $this->getService('utils/clihelper');
      Utils::printLn($helper->ansi(strtoupper("Welcome to the Modcontrol Help Center!"), 'color: magenta; font-weight: bold'));

      // 1) Define metadata for each command
      $commands = [
        'modules:list'   => [
          'usage' => 'modcontrol:modules:list [--limit=<n>] [--sort-by=<field>] [--sort-direction=<dir>] [--page=<n>]',
          'desc'  => 'Page through existing modules.',
          'flags' => [
            '--limit=<n>'          => 'Items per page (default 10)',
            '--sort-by=<field>'    => 'Field to sort by',
            '--sort-direction=<d>' => 'ASC or DESC (default ASC)',
            '--page=<n>'           => 'Page number (default 1)',
          ],
        ],
        'modules:create' => [
          'usage' => 'modcontrol:modules:create',
          'desc'  => 'Interactively create a new module.',
        ],
        'modules:remove' => [
          'usage' => 'modcontrol:modules:remove',
          'desc'  => 'Delete a module by its name.',
        ],
        'entities:list'   => [
          'usage' => 'modcontrol:entities:list [--module=<module_id>] [--limit=<n>] [--sort-by=<field>] [--sort-direction=<dir>] [--page=<n>]',
          'desc'  => 'Page through existing entities inside a module.',
          'flags' => [
            '--module=<module_name>'         => 'Module name to filter entities',
            '--limit=<n>'          => 'Items per page (default 10)',
            '--sort-by=<field>'    => 'Field to sort by',
            '--sort-direction=<d>' => 'ASC or DESC (default ASC)',
            '--page=<n>'           => 'Page number (default 1)',
          ],
        ],
        'entities:add' => [
          'usage' => 'modcontrol:entities:add [--module=<module_name>]',
          'desc'  => 'Interactively create a new entity inside a module.',
          'flags' => [
            '--module=<module_name>' => 'Module name to add the entity to',
          ],
        ],
        'entities:remove' => [
          'usage' => 'modcontrol:entities:remove',
          'desc'  => 'Interactively delete an entity by module and entity names.',
        ],
        'help'             => [
          'usage' => 'modcontrol:help',
          'desc'  => 'Show this help screen.',
        ],
      ];

      // 2) Summary table
      Utils::printLn($helper->ansi("\nAvailable commands:\n", 'color: cyan; text-decoration: underline'));

      $rows = [
        [
          'cmd'  => 'modcontrol:modules:list',
          'desc' => 'Page through existing modules',
          'opts' => '--limit, --sort-by, --sort-direction, --page',
        ],
        [
          'cmd'  => 'modcontrol:modules:create',
          'desc' => 'Interactively create a new module',
          'opts' => '(no flags)',
        ],
        [
          'cmd'  => 'modcontrol:modules:remove',
          'desc' => 'Delete a module by ID',
          'opts' => '(no flags)',
        ],
        [
          'cmd'  => 'modcontrol:entities:list',
          'desc' => 'Page through existing entities inside a module',
          'opts' => '--module, --limit, --sort-by, --sort-direction, --page',
        ],
        [
          'cmd'  => 'modcontrol:entities:add',
          'desc' => 'Interactively create a new entity inside a module',
          'opts' => '--module',
        ],
        [
          'cmd'  => 'modcontrol:entities:remove',
          'desc' => 'Interactively delete an entity by its module ID and name',
          'opts' => '(no flags)',
        ],
        [
          'cmd'  => 'modcontrol:help',
          'desc' => 'Show this help screen',
          'opts' => '(no flags)',
        ],
      ];

      $helper->table($rows, [
        'cmd'  => 'Command',
        'desc' => 'Description',
        'opts' => 'Options',
      ]);

      // 3) Detailed usage lists
      foreach ($commands as $cmd => $meta) {
        Utils::printLn($helper->ansi("\n{$cmd}", 'color: yellow; font-weight: bold'));
        Utils::printLn("  Usage:   {$meta['usage']}");
        Utils::printLn("  Purpose: {$meta['desc']}");

        if (!empty($meta['flags'])) {
          Utils::printLn("  Options:");
          $flagLines = [];
          foreach ($meta['flags'] as $flag => $explain) {
            $flagLines[] = "{$flag}  — {$explain}";
          }
          $helper->listItems($flagLines, false, '    •');
        }
      }

      Utils::printLn(''); // trailing newline
    });
  }

  private function setModuleName(array $args = []): string
  {
    // Extract and normalize our options
    if (isset($args['--module'])) {
      $moduleName = $args['--module'];
    } else {
      Utils::printLn("  >> Please, enter the Module Name: ");
      $moduleName = $this->getService('utils/misc')->persistentCliInput(
        function ($v) {
          return !empty($v) && is_string($v);
        },
        "Module name must be a string and cannot be empty. Please try again:"
      );
    }
    return $moduleName;
  }
}
