<?php

namespace Filemanager\Commands;

use Exception;
use SplitPHP\AppLoader;
use SplitPHP\Cli;
use SplitPHP\ModLoader;
use SplitPHP\Utils;
use SplitPHP\ObjLoader;

class Commands extends Cli
{
  public function init()
  {
    $this->addCommand('modules:list', function ($args) {
      // Extract and normalize our options
      $limit   = isset($args['--limit']) ? (int)$args['--limit'] : 10;
      $sortBy  = $args['--sort-by']         ?? null;
      $sortDir = $args['--sort-direction']  ?? 'ASC';
      unset($args['--limit'], $args['--sort-by'], $args['--sort-direction']);

      $page = isset($args['--page']) ? (int)$args['--page'] : 1;
      unset($args['--page']);

      // --- <== HERE: open STDIN in BLOCKING mode (no stream_set_blocking) ===>
      $stdin = fopen('php://stdin', 'r');
      // on *nix, disable line buffering & echo
      if (DIRECTORY_SEPARATOR !== '\\') {
        system('stty -icanon -echo');
      }

      $exit = false;
      while (! $exit) {
        // Clear screen + move cursor home
        if (DIRECTORY_SEPARATOR === '\\') {
          system('cls');
        } else {
          echo "\033[2J\033[H";
        }

        // Header & hints
        Utils::printLn($this->getService('utils/clihelper')->ansi("Welcome to the Modules List Command!\n", 'color: cyan; font-weight: bold'));
        Utils::printLn("HINTS:");
        Utils::printLn("  • --limit={$limit}   (items/page)");
        Utils::printLn("  • --sort-by={$sortBy}   --sort-direction={$sortDir}");
        if (DIRECTORY_SEPARATOR === '\\') {
          Utils::printLn("  • Press 'n' = next page, 'p' = previous page, 'q' = quit");
        } else {
          Utils::printLn("  • ←/→ arrows to navigate pages, 'q' to quit");
        }
        Utils::printLn("  • To see the list of entities inside each module, run 'modcontrol:entities:list --module=<module_name>'");
        Utils::printLn("  • Press 'ctrl+c' to exit at any time");
        Utils::printLn();

        // Fetch & render
        $params = array_merge($args, [
          '$limit' => $limit,
          '$limit_multiplier' => 1, // No multiplier for pagination
          '$page'  => $page,
        ]);
        if ($sortBy) {
          $params['$sort_by']        = $sortBy;
          $params['$sort_direction'] = $sortDir;
        }

        $rows = $this->getService('modcontrol/control')->list($params);

        if (empty($rows)) {
          Utils::printLn("  >> No modules found on page {$page}.");
        } else {
          Utils::printLn(" Page {$page} — showing " . count($rows) . " items");
          Utils::printLn(str_repeat('─', 60));
          $this->getService('utils/clihelper')->table($rows, [
            'id_mdc_module'           => 'ID',
            'dt_created'              => 'Created At',
            'ds_title'                => 'Module',
            'numEntities'             => 'Entities',
          ]);
        }

        // --- <== HERE: wait for exactly one keypress, blocking until you press ===>
        $c = fgetc($stdin);
        if (DIRECTORY_SEPARATOR === '\\') {
          $input = strtolower($c);
        } else {
          if ($c === "\033") {             // arrow keys start with ESC
            $input = $c . fgetc($stdin) . fgetc($stdin);
          } else {
            $input = $c;
          }
        }

        // Handle navigation
        if (DIRECTORY_SEPARATOR === '\\') {
          switch ($input) {
            case 'n':
              $page++;
              break;
            case 'p':
              $page = max(1, $page - 1);
              break;
            case 'q':
              $exit = true;
              break;
          }
        } else {
          switch ($input) {
            case "\033[C": // →
              $page++;
              break;
            case "\033[D": // ←
              $page = max(1, $page - 1);
              break;
            case 'q':
              $exit = true;
              break;
          }
        }
      }

      // Restore terminal settings on *nix
      if (DIRECTORY_SEPARATOR !== '\\') {
        system('stty sane');
      }

      // Cleanup
      fclose($stdin);
    });

    $this->addCommand('modules:create', function () {
      Utils::printLn("Welcome to the Modules Create Command!");
      Utils::printLn("This command will help you add a new module.");
      Utils::printLn();
      Utils::printLn(" >> Please follow the prompts to define your module informations.");
      Utils::printLn();
      Utils::printLn("  >> New Module:");
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

      Utils::printLn("  >> Module added successfully!");
      foreach ($record as $key => $value) {
        Utils::printLn("    -> {$key}: {$value}");
      }
    });

    $this->addCommand('modules:remove', function () {
      Utils::printLn("Welcome to the Module Removal Command!");
      Utils::printLn();
      $moduleId = readline("  >> Please, enter the Module ID you want to remove: ");

      $this->getDao('MDC_MODULE')
        ->filter('id_mdc_module')->equalsTo($moduleId)
        ->delete();
      Utils::printLn("  >> Module with ID {$moduleId} removed successfully!");
    });

    $this->addCommand('modules:map', function ($args) {
      require_once CORE_PATH . '/database/class.vocab.php';
      require_once CORE_PATH . '/database/' . DBTYPE . '/class.sql.php';
      require_once CORE_PATH . '/dbmigrations/class.migration.php';


      $moduleName = $args['--module'] ?? null;
      $appModName = readline("  >> Please, define the main app name as a module to be represented in this control (Ex.: 'General'): ");

      if ($moduleName !== null) {
        $module = $this->getDao('MDC_MODULE')
          ->filter('ds_title')->equalsTo($moduleName)
          ->first();
        if (!$module) {
          throw new Exception("Module with name {$moduleName} not found.");
        }
      }

      $entities = [];
      $mList = ModLoader::listMigrations($moduleName ?? null);
      foreach ($mList as $modName => $mData) {
        $entities = [];

        $modName = ucwords($modName);

        Utils::printLn("  >> Mapping module {$modName}'s entities...");
        foreach ($mData as $mDataItem) {
          $mobj = ObjLoader::load($mDataItem->filepath);
          $mobj->apply();
          $operations = $mobj->getOperations();

          if (empty($module = $this->getService('modcontrol/control')->get(['ds_title' => $modName]))) {
            $module = $this->getDao('MDC_MODULE')
              ->insert([
                'ds_key' => 'mdc-' . uniqid(),
                'ds_title' => $modName,
              ]);
          }

          foreach ($operations as $op) {
            if ($op->type != 'table') continue;

            $blueprint = $op->blueprint;
            $entity = [
              'id_mdc_module' => $module->id_mdc_module,
              'ds_entity_name' => $blueprint->getName(),
              'ds_entity_label' => $blueprint->getName(),
            ];

            $conflict = $this->getDao('MDC_MODULE_ENTITY')
              ->filter('id_mdc_module')->equalsTo($module->id_mdc_module)
              ->and('ds_entity_name')->equalsTo($entity['ds_entity_name'])
              ->first();

            if ($conflict) {
              continue;
            }

            $entities[] = $this->getDao('MDC_MODULE_ENTITY')
              ->insert($entity);
          }
        }

        Utils::printLn("  >> Module '{$modName}' mapped successfully with the following new entities:");
        Utils::printLn();
        foreach ($entities as $entity) {
          Utils::printLn("    -> {$entity->ds_entity_name} ({$entity->ds_entity_label})");
        }
        Utils::printLn();
      }

      // Insert a module to represent the app:
      if (empty($appMod = $this->getDao('MDC_MODULE')
        ->filter('ds_title')->equalsTo($appModName)
        ->first())) {
        $appMod = $this->getDao('MDC_MODULE')
          ->insert([
            'ds_key' => 'mdc-' . uniqid(),
            'ds_title' => $appModName,
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
          $entity = [
            'id_mdc_module' => $appMod->id_mdc_module,
            'ds_entity_name' => $blueprint->getName(),
            'ds_entity_label' => $blueprint->getName(),
          ];

          $entities[] = $this->getDao('MDC_MODULE_ENTITY')
            ->insert($entity);
        }
      }

      Utils::printLn("  >> Module '{$appModName}' mapped successfully with the following new entities:");
      Utils::printLn();
      foreach ($entities as $entity) {
        Utils::printLn("    -> {$entity->ds_entity_name} ({$entity->ds_entity_label})");
      }
      Utils::printLn();
    });

    $this->addCommand('entities:list', function ($args) {
      // Extract and normalize our options
      if (!isset($args['--module'])) {
        Utils::printLn("  >> Please specify a module using --module=<module_name>");
        return;
      }

      $moduleId = $args['--module'];
      $limit   = isset($args['--limit']) ? (int)$args['--limit'] : 10;
      $sortBy  = $args['--sort-by']         ?? null;
      $sortDir = $args['--sort-direction']  ?? 'ASC';
      unset($args['--limit'], $args['--sort-by'], $args['--sort-direction']);

      $page = isset($args['--page']) ? (int)$args['--page'] : 1;
      unset($args['--page']);

      // --- <== HERE: open STDIN in BLOCKING mode (no stream_set_blocking) ===>
      $stdin = fopen('php://stdin', 'r');
      // on *nix, disable line buffering & echo
      if (DIRECTORY_SEPARATOR !== '\\') {
        system('stty -icanon -echo');
      }

      $exit = false;
      while (! $exit) {
        // Clear screen + move cursor home
        if (DIRECTORY_SEPARATOR === '\\') {
          system('cls');
        } else {
          echo "\033[2J\033[H";
        }

        // Header & hints
        Utils::printLn($this->getService('utils/clihelper')->ansi("Welcome to the Module Entities List Command!\n", 'color: cyan; font-weight: bold'));
        Utils::printLn("HINTS:");
        Utils::printLn("  • --limit={$limit}   (items/page)");
        Utils::printLn("  • --sort-by={$sortBy}   --sort-direction={$sortDir}");
        if (DIRECTORY_SEPARATOR === '\\') {
          Utils::printLn("  • Press 'n' = next page, 'p' = previous page, 'q' = quit");
        } else {
          Utils::printLn("  • ←/→ arrows to navigate pages, 'q' to quit");
        }
        Utils::printLn("  • Press 'ctrl+c' to exit at any time");
        Utils::printLn();

        // Fetch & render
        $params = array_merge($args, [
          '$limit' => $limit,
          '$limit_multiplier' => 1, // No multiplier for pagination
          '$page'  => $page,
          'id_mdc_module' => $moduleId,
        ]);
        if ($sortBy) {
          $params['$sort_by']        = $sortBy;
          $params['$sort_direction'] = $sortDir;
        }

        $rows = $this->getService('modcontrol/control')->getModuleEntities($params);

        if (empty($rows)) {
          Utils::printLn("  >> No entities found on page {$page}.");
        } else {
          Utils::printLn(" Page {$page} — showing " . count($rows) . " items");
          Utils::printLn(str_repeat('─', 60));
          $this->getService('utils/clihelper')->table($rows, [
            'id_mdc_module_entity'           => 'ID',
            'dt_created'                     => 'Created At',
            'ds_entity_name'                 => 'Entity Name',
            'ds_entity_label'                => 'Entity Label',
            'modTitle'                       => 'Module',
          ]);
        }

        // --- <== HERE: wait for exactly one keypress, blocking until you press ===>
        $c = fgetc($stdin);
        if (DIRECTORY_SEPARATOR === '\\') {
          $input = strtolower($c);
        } else {
          if ($c === "\033") {             // arrow keys start with ESC
            $input = $c . fgetc($stdin) . fgetc($stdin);
          } else {
            $input = $c;
          }
        }

        // Handle navigation
        if (DIRECTORY_SEPARATOR === '\\') {
          switch ($input) {
            case 'n':
              $page++;
              break;
            case 'p':
              $page = max(1, $page - 1);
              break;
            case 'q':
              $exit = true;
              break;
          }
        } else {
          switch ($input) {
            case "\033[C": // →
              $page++;
              break;
            case "\033[D": // ←
              $page = max(1, $page - 1);
              break;
            case 'q':
              $exit = true;
              break;
          }
        }
      }

      // Restore terminal settings on *nix
      if (DIRECTORY_SEPARATOR !== '\\') {
        system('stty sane');
      }

      // Cleanup
      fclose($stdin);
    });

    $this->addCommand('entities:add', function ($args) {
      if (!isset($args['--module'])) {
        Utils::printLn("  >> Please specify a module using --module=<module_id>");
        return;
      }

      $moduleId = $args['--module'];
      Utils::printLn("Welcome to the Module Entity Add Command!");
      Utils::printLn("This command will help you add a new entity to the module with ID {$moduleId}.");
      Utils::printLn();
      Utils::printLn(" >> Please follow the prompts to define your entity informations.");
      Utils::printLn();
      Utils::printLn("  >> New Entity:");
      Utils::printLn("------------------------------------------------------");

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
      $moduleId = readline("  >> Please, enter the Module ID to remove an entity from: ");
      $entityName = readline("  >> Please, enter the Entity Name you want to remove: ");

      Utils::printLn("Welcome to the Module Entity Removal Command!");
      Utils::printLn();
      Utils::printLn("  >> Please confirm you want to remove the entity with name {$entityName}.");
      $confirm = readline("  >> Type 'yes' to confirm: ");
      if (strtolower($confirm) !== 'yes') {
        Utils::printLn("  >> Operation cancelled.");
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
          'desc'  => 'Delete a module by its ID.',
        ],
        'entities:list'   => [
          'usage' => 'modcontrol:entities:list [--module=<module_id>] [--limit=<n>] [--sort-by=<field>] [--sort-direction=<dir>] [--page=<n>]',
          'desc'  => 'Page through existing entities inside a module.',
          'flags' => [
            '--module=<n>'         => 'Module ID to filter entities',
            '--limit=<n>'          => 'Items per page (default 10)',
            '--sort-by=<field>'    => 'Field to sort by',
            '--sort-direction=<d>' => 'ASC or DESC (default ASC)',
            '--page=<n>'           => 'Page number (default 1)',
          ],
        ],
        'entities:create' => [
          'usage' => 'modcontrol:entities:create',
          'desc'  => 'Interactively create a new entity inside a module.',
        ],
        'entities:remove' => [
          'usage' => 'modcontrol:entities:remove',
          'desc'  => 'Interactively delete an entity by its module ID and its name.',
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
          'cmd'  => 'modcontrol:entities:create',
          'desc' => 'Interactively create a new entity inside a module',
          'opts' => '(no flags)',
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
}
