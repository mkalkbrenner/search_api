<?php

/**
 * @file
 * Contains \Drupal\search_api\Plugin\SearchApi\Datasource\ContentEntityDatasource.
 */

namespace Drupal\search_api\Plugin\SearchApi\Datasource;

use Drupal\Component\Utility\String;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataManager;
use Drupal\field\FieldConfigInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Drupal\search_api\Exception\SearchApiException;
use Drupal\search_api\Index\IndexInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Represents a datasource which exposes the content entities.
 *
 * @SearchApiDatasource(
 *   id = "entity",
 *   deriver = "Drupal\search_api\Plugin\SearchApi\Datasource\ContentEntityDatasourceDeriver"
 * )
 */
class ContentEntityDatasource extends DatasourcePluginBase {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManager|null
   */
  protected $entityManager;

  /**
   * The typed data manager.
   *
   * @var \Drupal\Core\TypedData\TypedDataManager|null
   */
  protected $typedDataManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    if (!empty($configuration['index']) && $configuration['index'] instanceof IndexInterface) {
      $this->setIndex($configuration['index']);
      unset($configuration['index']);
    }

    // Since defaultConfiguration() depends on the plugin definition, we need to
    // override the constructor and set the definition property before calling
    // that method.
    $this->pluginDefinition = $plugin_definition;
    $this->pluginId = $plugin_id;
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Drupal\search_api\Plugin\SearchApi\Datasource\ContentEntityDatasource $datasource */
    $datasource = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    /** @var $entity_manager \Drupal\Core\Entity\EntityManager */
    $entity_manager = $container->get('entity.manager');
    $datasource->setEntityManager($entity_manager);

    /** @var \Drupal\Core\TypedData\TypedDataManager $typed_data_manager */
    $typed_data_manager = $container->get('typed_data_manager');
    $datasource->setTypedDataManager($typed_data_manager);

    return $datasource;
  }

  /**
   * Retrieves the entity manager.
   *
   * @return \Drupal\Core\Entity\EntityManager
   *   The entity manager.
   */
  public function getEntityManager() {
    return $this->entityManager ?: \Drupal::entityManager();
  }

  /**
   * Retrieves the entity storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The entity storage.
   */
  protected function getEntityStorage() {
    return $this->getEntityManager()->getStorage($this->pluginDefinition['entity_type']);
  }

  /**
   * Returns the definition of this datasource's entity type.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface
   *   The entity type definition.
   */
  protected function getEntityType() {
    return $this->getEntityManager()->getDefinition($this->getEntityTypeId());
  }

  /**
   * Sets the entity manager.
   *
   * @param \Drupal\Core\Entity\EntityManager $entity_manager
   *   The new entity manager.
   *
   * @return $this
   */
  public function setEntityManager(EntityManager $entity_manager) {
    $this->entityManager = $entity_manager;
    return $this;
  }

  /**
   * Retrieves the typed data manager.
   *
   * @return \Drupal\Core\TypedData\TypedDataManager
   *   The typed data manager.
   */
  public function getTypedDataManager() {
    return $this->typedDataManager ?: \Drupal::typedDataManager();
  }

  /**
   * Sets the typed data manager.
   *
   * @param \Drupal\Core\TypedData\TypedDataManager $typed_data_manager
   *   The new typed data manager.
   *
   * @return $this
   */
  public function setTypedDataManager(TypedDataManager $typed_data_manager) {
    $this->typedDataManager = $typed_data_manager;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    $type = $this->getEntityTypeId();
    $properties = $this->getEntityManager()->getBaseFieldDefinitions($type);
    if ($bundles = $this->getIndexedBundles()) {
      foreach ($bundles as $bundle) {
        $properties += $this->getEntityManager()->getFieldDefinitions($type, $bundle);
      }
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    $items = $this->loadMultiple(array($id));
    return $items ? reset($items) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids) {
    $entity_ids = array();
    foreach ($ids as $item_id) {
      list($entity_id, $langcode) = explode(':', $item_id, 2);
      $entity_ids[$entity_id][$item_id] = $langcode;
    }

    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
    $entities = $this->getEntityStorage()->loadMultiple(array_keys($entity_ids));
    $missing = array();
    $items = array();
    foreach ($entity_ids as $entity_id => $langcodes) {
      foreach ($langcodes as $item_id => $langcode) {
        if (!empty($entities[$entity_id]) && $entities[$entity_id]->hasTranslation($langcode)) {
          $items[$item_id] = $entities[$entity_id]->getTranslation($langcode)->getTypedData();
        }
        else {
          $missing[] = $item_id;
        }
      }
    }
    // If we were unable to load some of the items, mark them as deleted.
    // @todo The index should be responsible for this, not individual
    //   datasources.
    if ($missing) {
      $this->getIndex()->trackItemsDeleted($this->getPluginId(), array_keys($missing));
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $new_config) {
    $old_config = $this->getConfiguration();
    $new_config += $this->defaultConfiguration();
    $this->configuration = $new_config;

    // We'd now check the items of which bundles need to be added to or removed
    // from tracking for this index. However, if the index is not tracking at
    // all (i.e., is disabled) currently, we can skip all that.
    if (!$this->index->status() || !$this->index->hasValidTracker()) {
      return;
    }

    if ($this->hasBundles()) {
      // First, check if the "default" setting changed and invert the set
      // bundles for the old config, so the following comparison makes sense.
      // @todo If the available bundles changed in between, this will still
      //   produce wrong results. And we should definitely only store a numeric
      //   array of the selected bundles, not the "checkboxes" raw format.
      if ($old_config['default'] != $new_config['default']) {
        foreach ($old_config['bundles'] as $bundle_key => $bundle) {
          if ($bundle_key == $bundle) {
            $old_config['bundles'][$bundle_key] = 0;
          }
          else {
            $old_config['bundles'][$bundle_key] = $bundle_key;
          }
        }
      }

      // Now, go through all the bundles and start/stop tracking for them
      // accordingly.
      $bundles_start = array();
      $bundles_stop = array();
      if ($diff = array_diff_assoc($new_config['bundles'], $old_config['bundles'])) {
        foreach ($diff as $bundle_key => $bundle) {
          if ($new_config['default'] == 0) {
            if ($bundle_key === $bundle) {
              $bundles_start[$bundle_key] = $bundle;
            }
            else {
              $bundles_stop[$bundle_key] = $bundle;
            }
          }
          else {
            if ($bundle_key === $bundle) {
              $bundles_stop[$bundle_key] = $bundle;
            }
            else {
              $bundles_start[$bundle_key] = $bundle;
            }
          }
        }
        if (!empty($bundles_start)) {
          if ($entity_ids = $this->getBundleItemIds(array_keys($bundles_start))) {
            $this->getIndex()->trackItemsInserted($this->getPluginId(), $entity_ids);
          }
        }
        if (!empty($bundles_stop)) {
          if ($entity_ids = $this->getBundleItemIds(array_keys($bundles_stop))) {
            $this->getIndex()->trackItemsDeleted($this->getPluginId(), $entity_ids);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    if ($this->hasBundles()) {
      return array(
        'default' => 1,
        'bundles' => array(),
      );
    }
    return array();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    if ($this->hasBundles()) {
      $bundles = $this->getEntityBundleOptions();
      $form['default'] = array(
        '#type' => 'radios',
        '#title' => $this->t('What should be indexed?'),
        '#options' => array(
          1 => $this->t('All except those selected'),
          0 => $this->t('None except those selected'),
        ),
        '#default_value' => $this->configuration['default'],
      );
      $form['bundles'] = array(
        '#type' => 'checkboxes',
        '#title' => $this->t('Bundles'),
        '#options' => $bundles,
        '#default_value' => $this->configuration['bundles'],
        '#size' => min(4, count($bundles)),
        '#multiple' => TRUE,
      );
    }

    return $form;
  }

  /**
   * Retrieves the available bundles of this entity type as an options list.
   *
   * @return array
   *   An associative array of bundle labels, keyed by the bundle name.
   */
  protected function getEntityBundleOptions() {
    $options = array();
    if (($bundles = $this->getEntityBundles())) {
      unset($bundles[$this->getEntityTypeId()]);
      foreach ($bundles as $bundle => $bundle_info) {
        $options[$bundle] = String::checkPlain($bundle_info['label']);
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item) {
    if ($item instanceof EntityAdapter) {
      return $item->getValue()->id() . ':' . $item->language();
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemLabel(ComplexDataInterface $item) {
    if ($item instanceof EntityAdapter) {
      return $item->getValue()->label();
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemUrl(ComplexDataInterface $item) {
    if ($item instanceof EntityAdapter) {
      return $item->getValue()->urlInfo();
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemIds($limit = '-1', $from = NULL) {
    // @todo Implement paging.
    return $this->getBundleItemIds();
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    $summary = '';
    if ($this->hasBundles()) {
      $bundles = array_values(array_intersect_key($this->getEntityBundleOptions(), array_filter($this->configuration['bundles'])));
      if ($this->configuration['default'] == TRUE) {
        $summary = $this->t('Excluded bundles: @bundles', array('@bundles' => implode(', ', $bundles)));
      }
      else {
        $summary = $this->t('Included bundles: @bundles', array('@bundles' => implode(', ', $bundles)));
      }
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    $plugin_definition = $this->getPluginDefinition();
    return $plugin_definition['entity_type'];
  }

  /**
   * Determines whether the entity type supports bundles.
   *
   * @return bool
   *   TRUE if the entity type supports bundles, FALSE otherwise.
   */
  protected function hasBundles() {
    return $this->getEntityType()->hasKey('bundle');
  }

  /**
   * Retrieves all bundles of this datasource's entity type.
   *
   * @return array
   *   An associative array of bundle infos, keyed by the bundle names.
   */
  protected function getEntityBundles() {
    return $this->hasBundles() ? $this->getEntityManager()->getBundleInfo($this->getEntityTypeId()) : array();
  }

  /**
   * Retrieves all item IDs of entities of the specified bundles.
   *
   * @param array $bundles
   *   (optional) The bundles for which all item IDs should be returned; or NULL
   *   to retrieve IDs from all enabled bundles in this datasource.
   *
   * @return array
   *   An array of all item IDs of these bundles.
   */
  protected function getBundleItemIds(array $bundles = NULL) {
    // If NULL was passed, use all enabled bundles.
    if (!isset($bundles)) {
      $bundles = $this->getIndexedBundles();
    }

    $select = \Drupal::entityQuery($this->getEntityTypeId());
    // If there are bundles to filter on, and they don't include all available
    // bundles, add the appropriate condition.
    if ($bundles) {
      if (count($bundles) != count($this->getEntityBundles())) {
        $select->condition($this->getEntityType()->getKey('bundle'), $bundles, 'IN');
      }
    }
    $entity_ids = $select->execute();

    // For all the loaded entities, compute all their item IDs (one for each
    // translation).
    $item_ids = array();
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    foreach (entity_load_multiple($this->getEntityTypeId(), $entity_ids) as $entity_id => $entity) {
      foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
        $item_ids[] = "$entity_id:$langcode";
      }
    }
    return $item_ids;
  }

  /**
   * Retrieves the bundles which will be indexed for this datasource.
   *
   * @return string[]
   *   The machine names of all indexed bundles for this datasource.
   */
  protected function getIndexedBundles() {
    if (!$this->hasBundles()) {
      return array();
    }

    $configuration = $this->getConfiguration();

    // If "default" is TRUE (i.e., "All except those selected"), remove all the
    // selected bundles from the available ones to compute the indexed bundles.
    if ($configuration['default']) {
      $bundles = $this->getEntityBundles();
      foreach ($configuration['bundles'] as $config_bundle) {
        if (isset($bundles[$config_bundle])) {
          unset($bundles[$config_bundle]);
        }
      }
    }
    // Otherwise, just return all selected bundles.
    else {
      $bundles = $configuration['bundles'];
      foreach ($bundles as $bundle_key => $bundle_value) {
        if ($bundle_value === 0) {
          unset($bundles[$bundle_key]);
        }
      }
    }
    return array_keys($bundles);
  }

  /**
   * {@inheritdoc}
   */
  public function getViewModes() {
    return $this->getEntityManager()->getViewModeOptions($this->getEntityTypeId());
  }

  /**
   * {@inheritdoc}
   */
  public function viewItem(ComplexDataInterface $item, $view_mode, $langcode = NULL) {
    try {
      if ($item instanceof EntityAdapter) {
        $entity = $item->getValue();
        $langcode = $langcode ?: $entity->language()->getId();
        return $this->getEntityManager()->getViewBuilder($this->getEntityTypeId())->view($entity, $view_mode, $langcode);
      }
    }
    catch (\Exception $e) {
      // The most common reason for this would be a
      // \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException in
      // getViewBuilder(), because the entity type definition doesn't specify a
      // view_builder class.
    }
    return array();
  }

  /**
   * {@inheritdoc}
   */
  public function viewMultipleItems(array $items, $view_mode, $langcode = NULL) {
    try {
      $view_builder = $this->getEntityManager()->getViewBuilder($this->getEntityTypeId());
      // Langcode passed, use that for viewing.
      if (isset($langcode)) {
        $entities = array();
        foreach ($items as $i => $item) {
          if ($item instanceof EntityAdapter) {
            $entities[$i] = $item->getValue();
          }
        }
        if ($entities) {
          return $view_builder->viewMultiple($entities, $view_mode, $langcode);
        }
        return array();
      }
      // Otherwise, separate the items by language, keeping the keys.
      $items_by_language = array();
      foreach ($items as $i => $item) {
        if ($item instanceof EntityInterface) {
          $items_by_language[$item->language()->getId()][$i] = $item;
        }
      }
      // Then build the items for each language. We initialize $build beforehand
      // and use array_replace() to add to it so the order stays the same.
      $build = array_fill_keys(array_keys($items), array());
      foreach ($items_by_language as $langcode => $language_items) {
        $build = array_replace($build, $view_builder->viewMultiple($language_items, $view_mode, $langcode));
      }
      return $build;
    }
    catch (\Exception $e) {
      // The most common reason for this would be a
      // \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException in
      // getViewBuilder(), because the entity type definition doesn't specify a
      // view_builder class.
      return array();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $this->dependencies += parent::calculateDependencies();

    $this->addDependency('module', $this->getEntityType()->getProvider());

    // @todo This should definitely be the responsibility of the index class,
    //   this is nothing specific to the datasource.
    $fields = array();
    foreach ($this->getIndex()->getFields() as $field) {
      if ($field->getDatasourceId() === $this->pluginId) {
        $fields[] = $field->getPropertyPath();
      }
    }
    if ($field_dependencies = $this->getFieldDependencies($this->getEntityTypeId(), $fields)) {
      $this->addDependencies(array('entity' => $field_dependencies));
    }

    return $this->dependencies;
  }

  /**
   * Returns an array of config entity dependencies.
   *
   * @param string $entity_type_id
   *   The entity type to which these fields are attached.
   * @param string[] $fields
   *   An array of property paths on items of this entity type.
   *
   * @return string[]
   *   An array of IDs of entities on which this datasource depends.
   */
  protected function getFieldDependencies($entity_type_id, $fields) {
    $field_dependencies = array();

    // Figure out which fields are directly on the item and which need to be
    // extracted from nested items.
    $direct_fields = array();
    $nested_fields = array();
    foreach ($fields as $field) {
      if (strpos($field, ':entity:') !== FALSE) {
        list($direct, $nested) = explode(':entity:', $field, 2);
        $nested_fields[$direct][] = $nested;
      }
      elseif (strpos($field, ':') === FALSE) {
        $direct_fields[] = $field;
      }
    }

    // Extract the config dependency name for direct fields.
    foreach (array_keys($this->getEntityManager()->getBundleInfo($entity_type_id)) as $bundle) {
      foreach ($this->getEntityManager()->getFieldDefinitions($entity_type_id, $bundle) as $field_name => $field_definition) {
        if ($field_definition instanceof FieldConfigInterface) {
          if (in_array($field_name, $direct_fields) || isset($nested_fields[$field_name])) {
            $field_dependencies[$field_definition->getConfigDependencyName()] = TRUE;
          }

          // Recurse for nested fields.
          if (isset($nested_fields[$field_name])) {
            $entity_type = $field_definition->getSetting('target_type');
            $field_dependencies += $this->getFieldDependencies($entity_type, $nested_fields[$field_name]);
          }
        }
      }
    }

    return array_keys($field_dependencies);
  }

  /**
   * Retrieves all indexes that are configured to index the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity for which to check.
   *
   * @return \Drupal\search_api\Index\IndexInterface[]
   *   All indexes that are configured to index the given entity (using this
   *   datasource class).
   */
  public static function getIndexesForEntity(ContentEntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    $datasource_id = 'entity:' . $entity_type;
    $entity_bundle = $entity->bundle();

    $index_names = \Drupal::entityQuery('search_api_index')
      ->condition('datasources.*', $datasource_id)
      ->execute();

    if (!$index_names) {
      return array();
    }

    /** @var \Drupal\search_api\Index\IndexInterface[] $indexes */
    $indexes = \Drupal::entityManager()->getStorage('search_api_index')->loadMultiple($index_names);

    // If the datasource's entity type supports bundles, we have to filter the
    // indexes for whether they also include the specific bundle of the given
    // entity. Otherwise, we are done.
    if ($entity_type !== $entity_bundle) {
      foreach ($indexes as $index_id => $index) {
        try {
          $config = $index->getDatasource($datasource_id)->getConfiguration();
          $default = !empty($config['default']);
          $bundle_set = !empty($config['bundles'][$entity_bundle]);
          if ($default == $bundle_set) {
            unset($indexes[$index_id]);
          }
        }
        catch (SearchApiException $e) {
          unset($indexes[$index_id]);
        }
      }
    }

    return $indexes;
  }

}
