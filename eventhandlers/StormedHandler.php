<?php

namespace Initbiz\SeoStorm\EventHandlers;

use App;
use Yaml;
use BackendAuth;
use October\Rain\Database\Model;
use System\Classes\PluginManager;
use Initbiz\SeoStorm\Models\Settings;
use Initbiz\SeoStorm\Models\SeoOptions;

class StormedHandler
{
    /**
     * Array of model classes to extend
     *
     * @var array
     */
    protected $modelClasses = [];

    public function subscribe($event)
    {
        $this->extendModels($event);
        if (App::runningInBackend()) {
            $this->extendFormWidgets($event);
        }
    }

    protected function extendModels($event)
    {
        foreach ($this->getStormedModels() as $stormedModelClass => $stormedModelDef) {
            if (!class_exists($stormedModelClass)) {
                continue;
            }

            $stormedModelClass::extend(function ($model) {
                $model->extendClassWith('Initbiz.SeoStorm.Behaviors.SeoStormed');

                if (!isset($model->morphOne)) {
                    $model->addDynamicProperty('morphOne');
                }

                $morphOne = $model->morphOne;

                $morphOne['seostorm_options'] = [
                    SeoOptions::class,
                    'name' => 'stormed',
                    'table' => 'initbiz_seostorm_seo_options',
                ];

                $model->morphOne = $morphOne;

                if (PluginManager::instance()->hasPlugin('RainLab.Translate')) {
                    if (!$model->propertyExists('translatable')) {
                        $model->addDynamicProperty('translatable', []);
                    }
                    $model->translatable = array_merge($model->translatable, $this->seoFieldsToTranslate());

                    /*
                     * Add translation support to database models
                     * We need to check if the database models implement all the
                     * required behaviors
                     */
                    if ($model instanceof Model) {
                        $requiredBehaviors = [
                            'RainLab\Translate\Behaviors\TranslatableModel',
                            'October\Rain\Database\Behaviors\Purgeable',
                        ];

                        if (!isset($model->implement)) {
                            $model->implement = [];
                        }

                        foreach ($requiredBehaviors as $behavior) {
                            $behaviorFound = false;
                            foreach ($model->implement as $use) {
                                $use = str_replace('.', '\\', trim($use));
                                if ('@' . $behavior === $use || $behavior === $use) {
                                    $behaviorFound = true;
                                    break;
                                }
                            }
                            if (!$behaviorFound) {
                                $model->implement[] = $behavior;
                            }
                        }
                    }
                }
            });

            // Define reverse of the relation in the SeoOptions model
            SeoOptions::extend(function ($model) use ($stormedModelClass) {
                $model->morphTo['stormed_models'][] = [
                    $stormedModelClass,
                    'name' => 'stormed',
                    'table' => 'initbiz_seostorm_seo_options',
                ];
            });
        }
    }

    protected function extendFormWidgets($event)
    {
        $event->listen('backend.form.extendFieldsBefore', function ($widget) {
            foreach ($this->getStormedModels() as $stormedModelClass => $stormedModelDef) {
                if ($widget->isNested === false && $widget->model instanceof $stormedModelClass) {
                    $placement = $stormedModelDef['placement'] ?? 'fields';
                    $prefix = $stormedModelDef['prefix'] ?? 'seo_options';
                    $excludeFields = $stormedModelDef['excludeFields'] ?? [];

                    $fields = $this->getSeoFieldsDefinitions($prefix, $excludeFields);

                    if ($placement === 'fields') {
                        $widget->fields = array_replace($widget->fields ?? [], $fields);
                    } elseif ($placement === 'tabs') {
                        $widget->tabs['fields'] = array_replace($widget->tabs['fields'] ?? [], $fields);
                    } elseif ($placement === 'secondaryTabs') {
                        $widget->secondaryTabs['fields'] = array_replace($widget->secondaryTabs['fields'] ?? [], $fields);
                    }
                    break;
                }
            }
        });
    }

    // helpers

    protected function getStormedModels()
    {
        if ($this->modelClasses) {
            return $this->modelClasses;
        }

        $methodName = 'registerStormedModels';

        $pluginManager = PluginManager::instance();
        $plugins = $pluginManager->getPlugins();

        $result = [];

        foreach ($plugins as $plugin) {
            if (method_exists($plugin, $methodName)) {
                $methodResult = $plugin->$methodName() ?? [];
                $result = array_merge($result, $methodResult);
            }
        }

        $this->modelClasses = $result;
        return $this->modelClasses;
    }

    protected function seoFieldsToTranslate()
    {
        $toTrans = [];
        foreach ($this->getSeoFieldsDefinitions() as $fieldKey => $fieldValue) {
            if (isset($fieldValue['trans']) && $fieldValue['trans'] === true) {
                $toTrans[] = $fieldKey;
            }
        }
        return $toTrans;
    }

    protected function getSeoFieldsDefinitions(string $prefix = 'seo_options', array $excludeFields = [])
    {
        $runningInFrontend = !App::runningInBackend();
        $user = BackendAuth::getUser();

        $fieldsDefinitions = [];

        if ($runningInFrontend || $user->hasAccess("initbiz.seostorm.meta")) {
            $fields = Yaml::parseFile(plugins_path('initbiz/seostorm/config/metafields.yaml'));
            $fieldsDefinitions = array_merge($fieldsDefinitions, $fields);
        }

        if (Settings::get('enable_og')) {
            if ($runningInFrontend || $user->hasAccess("initbiz.seostorm.og")) {
                $fields = Yaml::parseFile(plugins_path('initbiz/seostorm/config/ogfields.yaml'));
                $fieldsDefinitions = array_merge($fieldsDefinitions, $fields);
            }
        }

        if (Settings::get('enable_sitemap')) {
            if ($runningInFrontend || $user->hasAccess("initbiz.seostorm.sitemap")) {
                $fields = Yaml::parseFile(plugins_path('initbiz/seostorm/config/sitemapfields.yaml'));
                $fieldsDefinitions = array_merge($fieldsDefinitions, $fields);
            }
        }

        if ($runningInFrontend || $user->hasAccess("initbiz.seostorm.schema")) {
            $fields = Yaml::parseFile(plugins_path('initbiz/seostorm/config/schemafields.yaml'));
            $fieldsDefinitions = array_merge($fieldsDefinitions, $fields);
        }

        // Inverted excluding
        if (in_array('*', $excludeFields)) {
            $newExcludeFields = [];
            foreach ($fieldsDefinitions as $key => $fieldDef) {
                if (!in_array($key, $excludeFields)) {
                    $newExcludeFields[] = $key;
                }
            }
            $excludeFields = $newExcludeFields;
        }

        $readyFieldsDefs = [];
        foreach ($fieldsDefinitions as $key => $fieldDef) {
            if (!in_array($key, $excludeFields)) {
                $newKey = $prefix . "[" . $key . "]";
                // Make javascript trigger work with the prefixed fields
                if (isset($fieldDef['trigger'])) {
                    $fieldDef['trigger']['field'] = $prefix . "[" . $fieldDef['trigger']['field'] . "]";
                }
                $readyFieldsDefs[$newKey] = $fieldDef;
            }
        }

        return $readyFieldsDefs;
    }
}
